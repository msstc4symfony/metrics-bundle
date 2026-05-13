<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Test\Unit\Infrastructure\Collector;

use MaxShamaev\MetricsBundle\Infrastructure\Collector\InfoCollector;
use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use MaxShamaev\MetricsBundle\Infrastructure\Repository\MetricRepository;

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
