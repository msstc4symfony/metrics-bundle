<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Infrastructure\Collector;

use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\InfoCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepository;

final class InfoCollectorTest extends CollectorTestCase
{
    public function testSetMetricStoresGaugeWithPrefixLabels(): void
    {
        $collector = new InfoCollector($this->registry, new MetricRepository([]), 'app', 'cmp');

        $collector->setMetric(MetricLabelEnum::INFO_CPU_LOAD, 0.42);

        self::assertSame(
            [['app', 'cmp']],
            $this->labelValuesFor('symfony_' . MetricLabelEnum::INFO_CPU_LOAD->value),
        );
    }

    public function testSetMetricAcceptsIntValues(): void
    {
        $collector = new InfoCollector($this->registry, new MetricRepository([]), 'app', 'cmp');

        $collector->setMetric(MetricLabelEnum::INFO_FPM_TOTAL_PROCESSES, 12);

        self::assertTrue($this->familyExists('symfony_' . MetricLabelEnum::INFO_FPM_TOTAL_PROCESSES->value));
    }
}
