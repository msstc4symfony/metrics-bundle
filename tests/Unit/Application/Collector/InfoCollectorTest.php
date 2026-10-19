<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Application\Collector;

use Msstc4Symfony\MetricsBundle\Application\Collector\InfoCollector;
use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(InfoCollector::class)]
final class InfoCollectorTest extends RecorderTestCase
{
    public function testSetMetricSetsTheGauge(): void
    {
        (new InfoCollector($this->recorder))->setMetric(BuiltInMetric::INFO_CPU_LOAD, 0.42);

        self::assertSame([['set', BuiltInMetric::INFO_CPU_LOAD, 0.42, []]], $this->recorder->calls);
    }

    public function testSetMetricAcceptsIntValues(): void
    {
        (new InfoCollector($this->recorder))->setMetric(BuiltInMetric::INFO_FPM_TOTAL_PROCESSES, 12);

        self::assertSame([['set', BuiltInMetric::INFO_FPM_TOTAL_PROCESSES, 12, []]], $this->recorder->calls);
    }
}
