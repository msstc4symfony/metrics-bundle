<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\DependencyInjection\CompilerPass;

use InvalidArgumentException;
use LogicException;
use Msstc4Symfony\MetricsBundle\Application\LogChannel;
use Msstc4Symfony\MetricsBundle\Infrastructure\Messenger\MessengerQueueDepthReader;
use Msstc4Symfony\MetricsBundle\Presentation\EventListener\MessengerQueueDepthListener;
use Override;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;

/**
 * With msstc4symfony_metrics.messenger.queue_depth enabled, registers MessengerQueueDepthListener and the reader of
 * the sampled receivers. Transport names are taken as MessengerPass gives them to messenger:stats: the last "alias"
 * of a "messenger.receiver" tag, else the service id. Runs after the autoconfiguration passes (priority 100), so
 * receivers tagged through _instanceof are seen; the listener's tags are therefore set here.
 *
 * @internal
 */
final class RegisterMessengerQueueDepthPass implements CompilerPassInterface
{
    public const ENABLED_PARAMETER = 'msstc4symfony_metrics.messenger.queue_depth.enabled';

    public const TRANSPORTS_PARAMETER = 'msstc4symfony_metrics.messenger.queue_depth.transports';

    public const PERIOD_PARAMETER = 'msstc4symfony_metrics.messenger.queue_depth.period';

    public const PRIORITY = -10;

    private const RECEIVER_LOCATOR = 'messenger.receiver_locator';

    // Lowest priority, as InfoEventListener: after the response was sent.
    private const TERMINATE_PRIORITY = -4096;

    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::ENABLED_PARAMETER) || $container->getParameter(self::ENABLED_PARAMETER) !== true) {
            return;
        }

        if (!$container->hasDefinition(self::RECEIVER_LOCATOR) || !interface_exists(MessageCountAwareInterface::class)) {
            throw new LogicException('msstc4symfony_metrics.messenger.queue_depth needs Symfony Messenger (framework.messenger).');
        }

        $receivers = $this->receivers($container);
        $configured = $container->getParameter(self::TRANSPORTS_PARAMETER);
        // The configuration tree accepts names only.
        $listed = \is_array($configured) ? array_filter($configured, \is_string(...)) : [];
        $transports = $this->countable($container, $receivers, $listed === [] ? array_keys($receivers) : $this->known($listed, array_keys($receivers)), $listed !== []);

        if ($transports === []) {
            $container->log($this, 'No Messenger transport can count its messages: msstc4symfony_metrics.messenger.queue_depth samples nothing.');

            return;
        }

        $container->register(MessengerQueueDepthReader::class, MessengerQueueDepthReader::class)
            ->setArgument('$receivers', ServiceLocatorTagPass::register($container, array_intersect_key($receivers, array_flip($transports))))
        ;

        $container->register(MessengerQueueDepthListener::class, MessengerQueueDepthListener::class)
            ->setAutowired(true)
            ->setArgument('$reader', new Reference(MessengerQueueDepthReader::class))
            ->setArgument('$metricsCache', new Reference(AliasThrottleCachePass::THROTTLE_CACHE))
            ->setArgument('$transports', $transports)
            ->setArgument('$listed', $listed !== [])
            ->setArgument('$period', '%' . self::PERIOD_PARAMETER . '%')
            ->setArgument('$logger', new Reference('monolog.logger.' . LogChannel::NAME))
            ->addTag('kernel.event_listener', ['event' => 'kernel.terminate', 'method' => 'sample', 'priority' => self::TERMINATE_PRIORITY])
            ->addTag('kernel.event_listener', ['event' => WorkerRunningEvent::class, 'method' => 'sample'])
        ;
    }

    /**
     * @return array<string, Reference> by transport name
     */
    private function receivers(ContainerBuilder $container): array
    {
        $receivers = [];
        foreach ($container->findTaggedServiceIds('messenger.receiver') as $id => $tags) {
            $name = $id;
            foreach ($tags as $tag) {
                $alias = \is_array($tag) ? $tag['alias'] ?? null : null;
                if (\is_string($alias)) {
                    $name = $alias;
                }
            }

            $receivers[$name] = new Reference($id);
        }

        return $receivers;
    }

    /**
     * @param array<array-key, string> $listed
     * @param list<string> $available
     *
     * @return array<array-key, string>
     */
    private function known(array $listed, array $available): array
    {
        foreach ($listed as $transport) {
            if (!\in_array($transport, $available, true)) {
                throw new InvalidArgumentException(\sprintf('Messenger transport "%s" listed in %s does not exist; transports: "%s".', $transport, self::TRANSPORTS_PARAMETER, implode('", "', $available)));
            }
        }

        return $listed;
    }

    /**
     * Drops the receivers whose class is known not to count messages (framework transports are built by a factory:
     * their class is only known at run time); a listed one is a configuration error.
     *
     * @param array<string, Reference> $receivers
     * @param array<array-key, string> $transports
     *
     * @return list<string>
     */
    private function countable(ContainerBuilder $container, array $receivers, array $transports, bool $listed): array
    {
        $countable = [];
        foreach ($transports as $transport) {
            $reflection = $container->getReflectionClass($container->findDefinition((string) $receivers[$transport])->getClass(), false);
            if (!$reflection instanceof ReflectionClass || !$reflection->isInstantiable() || $reflection->implementsInterface(MessageCountAwareInterface::class)) {
                $countable[] = $transport;

                continue;
            }

            if ($listed) {
                throw new InvalidArgumentException(\sprintf('Messenger transport "%s" listed in %s cannot count its messages: %s does not implement %s.', $transport, self::TRANSPORTS_PARAMETER, $reflection->getName(), MessageCountAwareInterface::class));
            }
        }

        return $countable;
    }
}
