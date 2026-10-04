<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Infrastructure\Collector;

use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\InfoCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepository;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;

final class InfoCollectorTest extends CollectorTestCase
{
    public function testSetMetricStoresGaugeWithPrefixLabels(): void
    {
        $collector = new InfoCollector($this->registry, new MetricRepository([]), 'app', 'cmp');

        $collector->setMetric(MetricLabelEnum::INFO_CPU_LOAD, 0.42);

        self::assertSame(
            [['app', 'cmp']],
            RegistrySamples::labels($this->registry, MetricLabelEnum::INFO_CPU_LOAD),
        );
    }

    public function testSetMetricAcceptsIntValues(): void
    {
        $collector = new InfoCollector($this->registry, new MetricRepository([]), 'app', 'cmp');

        $collector->setMetric(MetricLabelEnum::INFO_FPM_TOTAL_PROCESSES, 12);

        self::assertTrue(RegistrySamples::exists($this->registry, MetricLabelEnum::INFO_FPM_TOTAL_PROCESSES));
    }
}
