<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\DependencyInjection\CompilerPass;

use InvalidArgumentException;
use Msstc4Symfony\MetricsBundle\Application\Collector\CacheCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Cache\ComputationCounter;
use Msstc4Symfony\MetricsBundle\Infrastructure\Cache\CountingCacheAdapter;
use Msstc4Symfony\MetricsBundle\Infrastructure\Cache\CountingCallbackWrapper;
use Msstc4Symfony\MetricsBundle\Infrastructure\Cache\CountingTagAwareCacheAdapter;
use Msstc4Symfony\MetricsBundle\Presentation\EventListener\CacheMetricsFlushListener;
use Override;
use ReflectionClass;
use Symfony\Component\Cache\Adapter\AbstractAdapter;
use Symfony\Component\Cache\Adapter\AbstractTagAwareAdapter;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ChainAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\Cache\Messenger\EarlyExpirationDispatcher;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Decorates the pools listed in msstc4symfony_metrics.cache.pools with a counting adapter, and registers the
 * collector and its flush listener only then. Runs after FrameworkBundle's CachePoolPass (priority 32), which derives
 * each pool's namespace from its service id and adds the early-expiration callback wrapper: the decoration renames
 * the pool later. A "tags: true" pool is the public TagAwareAdapter service; its inner pool is not decorated, so the
 * tag version lookups are not counted.
 *
 * @internal
 */
final class DecorateCachePoolsPass implements CompilerPassInterface
{
    public const POOLS_PARAMETER = 'msstc4symfony_metrics.cache.pools';

    public const REVERSIBLE_POOLS_PARAMETER = 'msstc4symfony_metrics.cache.reversible_inner_pools';

    public const DECORATOR_PREFIX = 'msstc4symfony_metrics.cache.';

    public const PRIORITY = 16;

    /**
     * Pools that cannot be listed, with the reason the configuration gives; aliases of them are rejected here.
     */
    public const UNMEASURABLE_POOLS = [
        'metrics.cache' => 'the bundle reads it on every request and worker loop to throttle its own sampling',
        'cache.messenger.restart_workers_signal' => 'every Messenger worker reads it on each loop',
    ];

    // Their get() computes through their own callback wrapper; other pools delegate get() to an inner pool.
    private const CALLBACK_WRAPPER_POOLS = [AbstractAdapter::class, AbstractTagAwareAdapter::class, TagAwareAdapter::class, ChainAdapter::class];

    private const FLUSH_PRIORITY = -8192;

    #[Override]
    public function process(ContainerBuilder $container): void
    {
        $pools = $container->hasParameter(self::POOLS_PARAMETER) ? $container->getParameter(self::POOLS_PARAMETER) : [];
        if (!\is_array($pools) || $pools === []) {
            return;
        }

        $this->registerCollector($container);

        $reversible = [];
        foreach (array_filter($pools, \is_string(...)) as $name) {
            $id = $this->resolveAlias($container, $name);
            $decoratorId = self::DECORATOR_PREFIX . $id;
            if (!$container->hasDefinition($decoratorId) && $this->decorate($container, $name, $id, $decoratorId)) {
                $reversible[] = $decoratorId . '.inner';
            }
        }

        if ($reversible !== []) {
            $container->setParameter(self::REVERSIBLE_POOLS_PARAMETER, $reversible);
        }
    }

    private function registerCollector(ContainerBuilder $container): void
    {
        $container->register(CacheCollector::class, CacheCollector::class)->setAutowired(true);

        // Registered after the autoconfiguration passes: the tags are set here. The Messenger events are names only.
        $listener = $container->register(CacheMetricsFlushListener::class, CacheMetricsFlushListener::class)->setAutowired(true);
        foreach (['kernel.terminate', 'console.terminate', WorkerMessageHandledEvent::class, WorkerMessageFailedEvent::class] as $event) {
            $listener->addTag('kernel.event_listener', ['event' => $event, 'method' => 'flush', 'priority' => self::FLUSH_PRIORITY]);
        }

        $listener->addTag('kernel.reset', ['method' => 'flush']);
    }

    /**
     * @return bool whether the pool is reversible, so that its inner id must stay reversible
     */
    private function decorate(ContainerBuilder $container, string $name, string $id, string $decoratorId): bool
    {
        if (!$container->hasDefinition($id)) {
            throw new InvalidArgumentException(\sprintf('Cache pool "%s" listed in %s does not exist.', $name, self::POOLS_PARAMETER));
        }

        foreach (array_keys(self::UNMEASURABLE_POOLS) as $unmeasurable) {
            if ($this->resolveAlias($container, $unmeasurable) === $id) {
                throw new InvalidArgumentException(\sprintf('Cache pool "%s" listed in %s is "%s", which cannot be measured.', $name, self::POOLS_PARAMETER, $unmeasurable));
            }
        }

        $pool = $container->getDefinition($id);
        $innerId = $decoratorId . '.inner';
        $reflection = $this->poolReflection($container, $name, $pool);

        $decorator = $container->register($decoratorId, $reflection->implementsInterface(TagAwareAdapterInterface::class) ? CountingTagAwareCacheAdapter::class : CountingCacheAdapter::class)
            ->setArguments([new Reference($innerId), $name, new Reference(CacheCollector::class)])
            ->setDecoratedService($id)
        ;

        $this->keepPoolName($pool, $id);
        $this->keepCallbackWrapperOnThePool($pool, $id, $innerId);

        if ($this->computesThroughACallbackWrapper($reflection)) {
            $counterId = $decoratorId . '.computations';
            $container->register($counterId, ComputationCounter::class);
            $decorator->addArgument(new Reference($counterId));
            $this->countComputations($pool, $innerId, new Reference($counterId));
        }

        if (!$pool->hasTag('container.reversible')) {
            return false;
        }

        // The tag moves to the decorator, but the early-expiration message must name the inner pool, the one
        // ContractsTrait hands to the dispatcher: KeepCachePoolsReversiblePass tags it again, and a behavior tag
        // (it stays on the decorated definition) keeps it from being inlined.
        $pool->addTag('container.do_not_inline');

        return true;
    }

    /**
     * Pools are child definitions of an adapter (or of another pool) until the optimization passes resolve them.
     * Factory-built pools such as cache.system declare AdapterInterface only, hence no stricter check.
     *
     * @return ReflectionClass<object>
     */
    private function poolReflection(ContainerBuilder $container, string $name, Definition $pool): ReflectionClass
    {
        $definition = $pool;
        while ($definition->getClass() === null && $definition instanceof ChildDefinition && $container->has($definition->getParent())) {
            $definition = $container->findDefinition($definition->getParent());
        }

        $reflection = $pool->isAbstract() ? null : $container->getReflectionClass($definition->getClass(), false);
        if (!$reflection instanceof ReflectionClass || !$reflection->implementsInterface(AdapterInterface::class)) {
            throw new InvalidArgumentException(\sprintf('Service "%s" listed in %s is not a Symfony cache pool: its class must implement %s.', $name, self::POOLS_PARAMETER, AdapterInterface::class));
        }

        return $reflection;
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function computesThroughACallbackWrapper(ReflectionClass $reflection): bool
    {
        foreach (self::CALLBACK_WRAPPER_POOLS as $class) {
            if ($reflection->getName() === $class || $reflection->isSubclassOf($class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Wraps the pool's callback wrapper, inside an early-expiration dispatcher (a recomputation sent to the bus is no
     * miss), or sets one around the pool's default wrapper.
     */
    private function countComputations(Definition $pool, string $innerId, Reference $counter): void
    {
        $calls = $pool->getMethodCalls();
        $wrapped = false;

        foreach ($calls as $index => $call) {
            $arguments = \is_array($call) && ($call[0] ?? null) === 'setCallbackWrapper' ? $call[1] ?? null : null;
            $wrapper = \is_array($arguments) ? $arguments[0] ?? null : null;
            if (!$wrapper instanceof Definition && !$wrapper instanceof Reference) {
                continue;
            }

            $wrapped = true;
            if ($wrapper instanceof Definition && $wrapper->getClass() === EarlyExpirationDispatcher::class && \array_key_exists(2, $wrapper->getArguments())) {
                $wrapper->replaceArgument(2, new Definition(CountingCallbackWrapper::class, [$wrapper->getArgument(2), $counter]));

                continue;
            }

            $calls[$index] = ['setCallbackWrapper', [new Definition(CountingCallbackWrapper::class, [$wrapper, $counter])]];
        }

        if (!$wrapped) {
            // setCallbackWrapper(null) returns the pool's current wrapper, as for Symfony's EarlyExpirationDispatcher.
            $previous = (new Definition('callable'))->setFactory([new Reference($innerId), 'setCallbackWrapper'])->addArgument(null);
            $calls[] = ['setCallbackWrapper', [new Definition(CountingCallbackWrapper::class, [$previous, $counter])]];
        }

        $pool->setMethodCalls($calls);
    }

    private function resolveAlias(ContainerBuilder $container, string $id): string
    {
        while ($container->hasAlias($id)) {
            $id = (string) $container->getAlias($id);
        }

        return $id;
    }

    /**
     * A pool without a "name" attribute is named after the id of the service that carries its tag; the decoration
     * moves the tag to the decorator, so cache:pool:prune and the profiler would show the decorator's id.
     */
    private function keepPoolName(Definition $pool, string $id): void
    {
        $attributes = $pool->getTag('cache.pool');
        if (!isset($attributes[0]) || !\is_array($attributes[0]) || isset($attributes[0]['name'])) {
            return;
        }

        $attributes[0]['name'] = $id;
        $tags = $pool->getTags();
        $tags['cache.pool'] = $attributes;
        $pool->setTags($tags);
    }

    /**
     * The early-expiration dispatcher takes over the pool's own callback wrapper through a factory on the pool's id,
     * which becomes the decorator: point it at the inner pool, as Symfony's CacheCollectorPass does.
     */
    private function keepCallbackWrapperOnThePool(Definition $pool, string $id, string $innerId): void
    {
        foreach ($pool->getMethodCalls() as $call) {
            $arguments = \is_array($call) && ($call[0] ?? null) === 'setCallbackWrapper' ? $call[1] ?? null : null;
            $dispatcher = \is_array($arguments) ? $arguments[0] ?? null : null;
            $wrapper = $dispatcher instanceof Definition ? $dispatcher->getArguments()[2] ?? null : null;
            if (!$wrapper instanceof Definition) {
                continue;
            }

            $factory = $wrapper->getFactory();
            $service = \is_array($factory) ? $factory[0] ?? null : null;
            if ($service instanceof Reference && (string) $service === $id && ($factory[1] ?? null) === 'setCallbackWrapper') {
                $wrapper->setFactory([new Reference($innerId), 'setCallbackWrapper']);
            }
        }
    }
}
