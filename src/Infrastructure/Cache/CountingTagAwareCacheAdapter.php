<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Cache;

use Msstc4Symfony\MetricsBundle\Application\Collector\CacheCollector;
use Override;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\Cache\ResettableInterface;
use Symfony\Contracts\Cache\NamespacedPoolInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * CountingCacheAdapter for a tag-aware pool ("tags: true", Redis tag-aware adapters): the lookups the pool makes in
 * its inner pool for tag versions are not seen here.
 *
 * @internal decorates the listed pools, see DecorateCachePoolsPass
 */
final readonly class CountingTagAwareCacheAdapter implements TagAwareAdapterInterface, TagAwareCacheInterface, NamespacedPoolInterface, PruneableInterface, ResettableInterface
{
    use CountsCacheLookups;

    public function __construct(
        private TagAwareAdapterInterface $pool,
        private string $poolName,
        private CacheCollector $collector,
        private ?ComputationCounter $computations = null,
    ) {
    }

    /**
     * @param array<array-key, string> $tags
     */
    #[Override]
    public function invalidateTags(array $tags): bool
    {
        return $this->pool->invalidateTags($tags);
    }
}
