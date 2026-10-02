<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Infrastructure\Collector;

use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\ProfilingCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepository;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;

final class ProfilingCollectorTest extends CollectorTestCase
{
    public function testSetProfilingSpanDurationLowercasesAndNormalizes(): void
    {
        $this->build()->setProfilingSpanDuration('Order Service::process', 0.15);

        self::assertSame(
            [['app', 'cmp', 'order_service_process']],
            RegistrySamples::labels($this->registry, MetricLabelEnum::PROFILING_SPAN_DURATION_HISTOGRAM_SECONDS, '_count'),
        );
    }

    public function testSetProfilingSpanDurationKeepsAlphaNumericHyphenAndUnderscore(): void
    {
        $this->build()->setProfilingSpanDuration('span-1_v2', 0.05);

        self::assertSame(
            [['app', 'cmp', 'span-1_v2']],
            RegistrySamples::labels($this->registry, MetricLabelEnum::PROFILING_SPAN_DURATION_HISTOGRAM_SECONDS, '_count'),
        );
    }

    private function build(): ProfilingCollector
    {
        return new ProfilingCollector($this->registry, new MetricRepository([]), 'app', 'cmp');
    }
}
