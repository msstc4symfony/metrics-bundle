<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Presentation\EventListener;

use Msstc4Symfony\MetricsBundle\Application\Collector\CacheCollector;

/**
 * Writes the cache lookups counted during a request, command or message. DecorateCachePoolsPass registers it, with
 * the lowest priority on the terminate and Messenger events and on kernel.reset: lookups made by other terminate
 * listeners still belong to this unit of work.
 *
 * @internal
 */
final readonly class CacheMetricsFlushListener
{
    public function __construct(
        private CacheCollector $collector,
    ) {
    }

    public function flush(): void
    {
        $this->collector->flush();
    }
}
