<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Test\Unit\Infrastructure\Collector;

use MaxShamaev\MetricsBundle\Infrastructure\Collector\ProfilingCollector;
use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use MaxShamaev\MetricsBundle\Infrastructure\Repository\MetricRepository;

final class ProfilingCollectorTest extends CollectorTestCase
{
    public function testSetProfilingSpanDurationLowercasesAndNormalizes(): void
    {
        $this->build()->setProfilingSpanDuration('Order Service::process', 0.15);

        $samples = $this->labelValuesFor('symfony_' . MetricLabelEnum::PROFILING_SPAN_DURATION_HISTOGRAM_SECONDS->value);
        self::assertNotEmpty($samples);
        self::assertSame(['app', 'cmp', 'order_service_process'], array_slice($samples[0], 0, 3));
    }

    public function testSetProfilingSpanDurationKeepsAlphaNumericHyphenAndUnderscore(): void
    {
        $this->build()->setProfilingSpanDuration('span-1_v2', 0.05);

        $samples = $this->labelValuesFor('symfony_' . MetricLabelEnum::PROFILING_SPAN_DURATION_HISTOGRAM_SECONDS->value);
        self::assertNotEmpty($samples);
        self::assertSame(['app', 'cmp', 'span-1_v2'], array_slice($samples[0], 0, 3));
    }

    private function build(): ProfilingCollector
    {
        return new ProfilingCollector($this->registry, new MetricRepository([]), 'app', 'cmp');
    }
}
