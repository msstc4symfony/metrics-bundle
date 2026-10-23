<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Infrastructure\Cache;

use Msstc4Symfony\MetricsBundle\Application\Collector\CacheCollector;
use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Infrastructure\Cache\CountingTagAwareCacheAdapter;
use Msstc4Symfony\MetricsBundle\Test\Support\RecordingMetricRecorder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Contracts\Cache\ItemInterface;

final class CountingTagAwareCacheAdapterTest extends TestCase
{
    public function testCountsTheLookupsOfTheTagAwarePoolOnly(): void
    {
        $recorder = new RecordingMetricRecorder();
        $collector = new CacheCollector($recorder);
        $pool = new CountingTagAwareCacheAdapter(new TagAwareAdapter(new ArrayAdapter()), 'app.tagged', $collector);
        $compute = static function (ItemInterface $item): string {
            $item->tag(['users']);

            return 'value';
        };

        $pool->get('a', $compute);
        $pool->get('a', $compute);
        self::assertTrue($pool->invalidateTags(['users']));
        $pool->get('a', $compute);
        $pool->withSubNamespace('tenant')->getItem('a');
        $collector->flush();

        self::assertSame(
            [
                ['incrementBy', BuiltInMetric::CACHE_REQUEST, 3, ['app.tagged', 'miss']],
                ['incrementBy', BuiltInMetric::CACHE_REQUEST, 1, ['app.tagged', 'hit']],
            ],
            $recorder->calls,
        );
    }

    public function testASubNamespaceKeepsTheTagAwareInterface(): void
    {
        $pool = new CountingTagAwareCacheAdapter(new TagAwareAdapter(new ArrayAdapter()), 'app.tagged', new CacheCollector(new RecordingMetricRecorder()));

        self::assertInstanceOf(CountingTagAwareCacheAdapter::class, $pool->withSubNamespace('tenant'));
    }
}
