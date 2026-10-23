<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\DependencyInjection\CompilerPass;

use Msstc4Symfony\MetricsBundle\Presentation\EventListener\InfoEventListener;
use Override;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Points the bundle's throttle cache at the application's metrics.cache pool, or at the CacheInterface alias
 * (cache.app) without one, and passes it to its readers explicitly: symfony/dependency-injection 8.1 deprecates
 * matching the named alias by the $metricsCache parameter name. An argument the application set is kept.
 *
 * @internal
 */
final class AliasThrottleCachePass implements CompilerPassInterface
{
    public const THROTTLE_CACHE = 'msstc4symfony_metrics.throttle_cache';

    private const METRICS_POOL = 'metrics.cache';

    #[Override]
    public function process(ContainerBuilder $container): void
    {
        $container->setAlias(self::THROTTLE_CACHE, $container->has(self::METRICS_POOL) ? self::METRICS_POOL : CacheInterface::class);

        $listener = $container->hasDefinition(InfoEventListener::class) ? $container->getDefinition(InfoEventListener::class) : null;
        if ($listener instanceof Definition && !\array_key_exists('$metricsCache', $listener->getArguments())) {
            $listener->setArgument('$metricsCache', new Reference(self::THROTTLE_CACHE));
        }
    }
}
