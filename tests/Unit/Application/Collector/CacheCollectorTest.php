<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Application\Collector;

use Msstc4Symfony\MetricsBundle\Application\Collector\CacheCollector;
use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(CacheCollector::class)]
final class CacheCollectorTest extends RecorderTestCase
{
    public function testNothingIsWrittenBeforeAFlush(): void
    {
        $collector = new CacheCollector($this->recorder);

        $collector->recordLookup('app.users', true);

        self::assertSame([], $this->recorder->calls);
    }

    public function testAFlushWritesOneIncrementPerPoolAndResult(): void
    {
        $collector = new CacheCollector($this->recorder);

        $collector->recordLookup('app.users', true);
        $collector->recordLookup('app.users', false);
        $collector->recordLookup('app.users', true);
        $collector->recordLookup('app.users', true);
        $collector->recordLookup('app.users', true);
        $collector->recordLookup('app.orders', false);
        $collector->flush();

        self::assertSame(
            [
                ['incrementBy', BuiltInMetric::CACHE_REQUEST, 4, ['app.users', 'hit']],
                ['incrementBy', BuiltInMetric::CACHE_REQUEST, 1, ['app.users', 'miss']],
                ['incrementBy', BuiltInMetric::CACHE_REQUEST, 1, ['app.orders', 'miss']],
            ],
            $this->recorder->calls,
        );
    }

    public function testAFlushStartsAFreshAggregation(): void
    {
        $collector = new CacheCollector($this->recorder);

        $collector->recordLookup('app.users', false);
        $collector->flush();
        $collector->flush();
        $collector->recordLookup('app.users', false);
        $collector->flush();

        self::assertSame(
            [
                ['incrementBy', BuiltInMetric::CACHE_REQUEST, 1, ['app.users', 'miss']],
                ['incrementBy', BuiltInMetric::CACHE_REQUEST, 1, ['app.users', 'miss']],
            ],
            $this->recorder->calls,
        );
    }

    public function testAFlushHappensEveryThousandPendingLookups(): void
    {
        $collector = new CacheCollector($this->recorder);

        for ($i = 1; $i < CacheCollector::FLUSH_THRESHOLD; $i++) {
            $collector->recordLookup('app.users', $i % 2 === 0);
        }

        self::assertSame([], $this->recorder->calls);

        $collector->recordLookup('app.users', true);

        self::assertSame(
            [
                ['incrementBy', BuiltInMetric::CACHE_REQUEST, 500, ['app.users', 'miss']],
                ['incrementBy', BuiltInMetric::CACHE_REQUEST, 500, ['app.users', 'hit']],
            ],
            $this->recorder->calls,
        );

        $this->recorder->calls = [];
        for ($i = 1; $i < CacheCollector::FLUSH_THRESHOLD; $i++) {
            $collector->recordLookup('app.users', true);
        }

        self::assertSame([], $this->recorder->calls, 'the count starts again after a flush');

        $collector->recordLookup('app.users', true);

        self::assertSame([['incrementBy', BuiltInMetric::CACHE_REQUEST, 1000, ['app.users', 'hit']]], $this->recorder->calls);
    }

    public function testPendingLookupsAreWrittenWhenTheCollectorGoesAway(): void
    {
        $collector = new CacheCollector($this->recorder);
        $collector->recordLookup('app.users', false);

        unset($collector);

        self::assertSame([['incrementBy', BuiltInMetric::CACHE_REQUEST, 1, ['app.users', 'miss']]], $this->recorder->calls);
    }
}
