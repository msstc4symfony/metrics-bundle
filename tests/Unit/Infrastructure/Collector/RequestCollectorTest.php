<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Infrastructure\Collector;

use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\RequestCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepository;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;

final class RequestCollectorTest extends CollectorTestCase
{
    public function testIncRequestTotal(): void
    {
        $this->build()->incRequestTotal('GET', 'order_show');

        self::assertSame(
            [['app', 'cmp', 'GET', 'order_show']],
            RegistrySamples::labels($this->registry, MetricLabelEnum::HTTP_REQUEST),
        );
    }

    public function testIncRequestTotalNullRouteFallsBackToUnknown(): void
    {
        $this->build()->incRequestTotal('GET', null);

        self::assertSame(
            [['app', 'cmp', 'GET', 'unknown']],
            RegistrySamples::labels($this->registry, MetricLabelEnum::HTTP_REQUEST),
        );
    }

    public function testIncResponseTotalLabelsByStatusCode(): void
    {
        $this->build()->incResponseTotal('GET', 'order_show', 404);

        self::assertSame(
            [['app', 'cmp', 'GET', 'order_show', '404']],
            RegistrySamples::labels($this->registry, MetricLabelEnum::HTTP_RESPONSE),
        );
    }

    public function testSetRequestDurationHistogramRecorded(): void
    {
        $this->build()->setRequestDuration('GET', 'order_show', 0.12);

        self::assertTrue(RegistrySamples::exists($this->registry, MetricLabelEnum::REQUEST_DURATION_HISTOGRAM_SECONDS));
    }

    public function testSetRequestDurationSummaryRecorded(): void
    {
        $this->build()->setRequestDurationSummary('GET', 'order_show', 0.18);

        self::assertTrue(RegistrySamples::exists($this->registry, MetricLabelEnum::REQUEST_DURATION_SUMMARY_SECONDS));
    }

    public function testSetRequestDurationNullRouteFallsBack(): void
    {
        $this->build()->setRequestDuration('GET', null, 0.1);

        self::assertTrue(RegistrySamples::exists($this->registry, MetricLabelEnum::REQUEST_DURATION_HISTOGRAM_SECONDS));
    }

    private function build(): RequestCollector
    {
        return new RequestCollector($this->registry, new MetricRepository([]), 'app', 'cmp');
    }
}
