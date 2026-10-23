<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\DependencyInjection\CompilerPass;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use Msstc4Symfony\MetricsBundle\Infrastructure\Guzzle\ChainedClientConfigurator;
use Msstc4Symfony\MetricsBundle\Infrastructure\Guzzle\MetricsGuzzleMiddleware;
use Override;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Adds the metrics middleware (registered in services.php, before the optimization passes, so its arguments are
 * autowired) to every Guzzle client service. The same three paths as the tracing and profiling bundles: a client
 * constructed by `GuzzleHttp\Client::__construct()` gets the middleware in its `handler` config; other clients are
 * configured after creation through `getConfig()`; clients without it are only reported in the container
 * compilation log. Runs as TYPE_BEFORE_REMOVING, after the named arguments were resolved.
 *
 * @internal
 */
final class AddGuzzleMonitorPass implements CompilerPassInterface
{
    private const UNREACHABLE_CLIENT_LOG = 'Guzzle client "%s" is not measured: its handler cannot be set from an array config of %s::__construct() and it has no getConfig(); '
        . 'configure it as a %s with a literal config array or a %s handler.';

    private const BARE_HANDLER_LOG = 'Guzzle client "%s" is not measured: its "handler" is not a %s.';

    // Guzzle 8 builds its default handler from these options and rejects them next to a given handler.
    private const DEFAULT_HANDLER_OPTIONS = ['max_host_connections', 'max_total_connections', 'transport_sharing', 'multiplex'];

    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (!interface_exists(ClientInterface::class) || !$container->hasDefinition(MetricsGuzzleMiddleware::class)) {
            return;
        }

        $metrics = new Reference(MetricsGuzzleMiddleware::class);

        foreach ($container->getDefinitions() as $id => $definition) {
            if ($definition->isAbstract()) {
                continue;
            }

            // getReflectionClass() resolves parameters and survives classes whose parent comes from a missing
            // package; is_a() would autoload them into a fatal error.
            $reflection = $container->getReflectionClass($definition->getClass(), false);
            if (!$reflection instanceof ReflectionClass || !$reflection->implementsInterface(ClientInterface::class)) {
                continue;
            }

            if ($this->measureThroughConfig($container, $id, $definition, $reflection, $metrics)) {
                continue;
            }

            if (!$reflection->hasMethod('getConfig')) {
                $container->log($this, \sprintf(self::UNREACHABLE_CLIENT_LOG, $id, Client::class, Client::class, HandlerStack::class));

                continue;
            }

            $configurator = $definition->getConfigurator();
            $definition->setConfigurator($configurator === null
                ? [$metrics, 'addHandler']
                : [new Definition(ChainedClientConfigurator::class, [$configurator, $metrics]), '__invoke']);
        }
    }

    /**
     * @param ReflectionClass<object> $reflection
     *
     * @return bool whether the client needs nothing more: measured through its config or left alone
     */
    private function measureThroughConfig(ContainerBuilder $container, string $id, Definition $definition, ReflectionClass $reflection, Reference $metrics): bool
    {
        if ($definition->getFactory() !== null || $reflection->getConstructor()?->getDeclaringClass()->getName() !== Client::class) {
            return false;
        }

        $config = $definition->getArguments()[0] ?? [];

        // A parameter or expression is resolved only later; its content cannot be extended here.
        if (!\is_array($config)) {
            return false;
        }

        $handler = $config['handler'] ?? null;
        if ($handler === null && array_intersect(self::DEFAULT_HANDLER_OPTIONS, array_keys($config)) !== []) {
            return false;
        }

        $isHandlerStack = match (true) {
            $handler === null => true,
            $handler instanceof Reference, $handler instanceof Definition => $this->isHandlerStack($container, $handler),
            // A literal value of the config array (a callable name, an object) is no HandlerStack service.
            default => false,
        };
        if ($isHandlerStack === null) {
            // addHandler() checks the handler the client actually got.
            return false;
        }

        if (!$isHandlerStack) {
            $container->log($this, \sprintf(self::BARE_HANDLER_LOG, $id, HandlerStack::class));

            return true;
        }

        $config['handler'] = (new Definition(HandlerStack::class, [$handler]))
            ->setFactory([$metrics, 'decorateHandler'])
        ;
        $definition->setArgument(0, $config);

        return true;
    }

    /**
     * Only a stack can take the middleware; decorateHandler() would return anything else unchanged.
     *
     * @return bool|null null when the class of the handler is not known at compile time
     */
    private function isHandlerStack(ContainerBuilder $container, Reference|Definition $handler): ?bool
    {
        if ($handler instanceof Reference) {
            if (!$container->has((string) $handler)) {
                return null;
            }

            $handler = $container->findDefinition((string) $handler);
        }

        $reflection = $container->getReflectionClass($handler->getClass(), false);
        if (!$reflection instanceof ReflectionClass) {
            return null;
        }

        return $reflection->getName() === HandlerStack::class || $reflection->isSubclassOf(HandlerStack::class);
    }
}
