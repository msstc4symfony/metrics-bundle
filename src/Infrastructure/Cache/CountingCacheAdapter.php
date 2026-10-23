<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Cache;

use Msstc4Symfony\MetricsBundle\Application\Collector\CacheCollector;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\Cache\ResettableInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\NamespacedPoolInterface;

/**
 * Counts the lookups of a cache pool listed in msstc4symfony_metrics.cache.pools. Like Symfony's TraceableAdapter it
 * implements every optional interface of a pool; get(), prune(), reset() and withSubNamespace() only reach a pool
 * that supports them.
 *
 * @internal decorates the listed pools, see DecorateCachePoolsPass
 */
final readonly class CountingCacheAdapter implements AdapterInterface, CacheInterface, NamespacedPoolInterface, PruneableInterface, ResettableInterface
{
    use CountsCacheLookups;

    public function __construct(
        private AdapterInterface $pool,
        private string $poolName,
        private CacheCollector $collector,
        private ?ComputationCounter $computations = null,
    ) {
    }
}
