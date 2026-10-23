<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Application\Collector;

use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Application\Port\MetricBatchRecorderInterface;

/**
 * Cache lookups are far too frequent for one storage write each: they are counted in memory and written with one
 * increment per pool and result when flush() runs at the end of a request, command or message.
 *
 * @internal
 */
final class CacheCollector
{
    /** Long commands and workers without a terminate event write at least this often. */
    public const FLUSH_THRESHOLD = 1000;

    /** @var array<string, array{hit?: positive-int, miss?: positive-int}> */
    private array $lookups = [];

    /** @var non-negative-int */
    private int $pending = 0;

    public function __construct(
        private readonly MetricBatchRecorderInterface $recorder,
    ) {
    }

    public function __destruct()
    {
        $this->flush();
    }

    public function recordLookup(string $pool, bool $hit): void
    {
        $result = $hit ? 'hit' : 'miss';
        $this->lookups[$pool][$result] = ($this->lookups[$pool][$result] ?? 0) + 1;

        if (++$this->pending >= self::FLUSH_THRESHOLD) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        $lookups = $this->lookups;
        $this->lookups = [];

        $this->pending = 0;

        foreach ($lookups as $pool => $results) {
            foreach ($results as $result => $amount) {
                $this->recorder->incrementBy(BuiltInMetric::CACHE_REQUEST, $amount, [$pool, $result]);
            }
        }
    }
}
