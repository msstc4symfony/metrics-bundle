<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Presentation\EventListener;

use Msstc4Symfony\MetricsBundle\Application\Collector\CacheCollector;
use Msstc4Symfony\MetricsBundle\Presentation\EventListener\CacheMetricsFlushListener;
use Msstc4Symfony\MetricsBundle\Test\Support\RecordingMetricRecorder;
use PHPUnit\Framework\TestCase;

final class CacheMetricsFlushListenerTest extends TestCase
{
    public function testFlushWritesTheAggregatedLookups(): void
    {
        $recorder = new RecordingMetricRecorder();
        $collector = new CacheCollector($recorder);
        $collector->recordLookup('app.users', true);

        (new CacheMetricsFlushListener($collector))->flush();

        self::assertCount(1, $recorder->calls);
    }
}
