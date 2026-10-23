<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\Cache;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * The pools as applications autowire them by name.
 */
final readonly class CachePoolsConsumer
{
    public function __construct(
        #[Target(CachePoolsKernel::PLAIN_POOL)]
        public CacheInterface $plain,
        #[Target(CachePoolsKernel::PLAIN_POOL)]
        public TagAwareCacheInterface $plainTaggable,
        #[Target(CachePoolsKernel::TAGGED_POOL)]
        public TagAwareCacheInterface $tagged,
        #[Target(CachePoolsKernel::CHAIN_POOL)]
        public CacheInterface $chain,
        #[Target(CachePoolsKernel::UNLISTED_POOL)]
        public CacheItemPoolInterface $unlisted,
    ) {
    }
}
