<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Test\Unit\Infrastructure\Collector;

use MaxShamaev\MetricsBundle\Infrastructure\Collector\RequestCollector;
use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use MaxShamaev\MetricsBundle\Infrastructure\Repository\MetricRepository;

final class RequestCollectorTest extends CollectorTestCase
{
    public function testIncRequestTotal(): void
    {
        $this->build()->incRequestTotal('GET', 'order_show');

        self::assertSame(
            [['app', 'cmp', 'GET', 'order_show']],
            $this->labelValuesFor('symfony_' . MetricLabelEnum::HTTP_REQUEST->value),
        );
    }

    public function testIncRequestTotalNullRouteFallsBackToUnknown(): void
    {
        $this->build()->incRequestTotal('GET', null);

        self::assertSame(
            [['app', 'cmp', 'GET', 'unknown']],
            $this->labelValuesFor('symfony_' . MetricLabelEnum::HTTP_REQUEST->value),
        );
    }

    public function testIncResponseTotalLabelsByStatusCode(): void
    {
        $this->build()->incResponseTotal('GET', 'order_show', 404);

        self::assertSame(
            [['app', 'cmp', 'GET', 'order_show', '404']],
            $this->labelValuesFor('symfony_' . MetricLabelEnum::HTTP_RESPONSE->value),
        );
    }

    public function testSetRequestDurationHistogramRecorded(): void
    {
        $this->build()->setRequestDuration('GET', 'order_show', 0.12);

        self::assertTrue($this->familyExists('symfony_' . MetricLabelEnum::REQUEST_DURATION_HISTOGRAM_SECONDS->value));
    }

    public function testSetRequestDurationSummaryRecorded(): void
    {
        $this->build()->setRequestDurationSummary('GET', 'order_show', 0.18);

        self::assertTrue($this->familyExists('symfony_' . MetricLabelEnum::REQUEST_DURATION_SUMMARY_SECONDS->value));
    }

    public function testSetRequestDurationNullRouteFallsBack(): void
    {
        $this->build()->setRequestDuration('GET', null, 0.1);

        self::assertTrue($this->familyExists('symfony_' . MetricLabelEnum::REQUEST_DURATION_HISTOGRAM_SECONDS->value));
    }

    private function build(): RequestCollector
    {
        return new RequestCollector($this->registry, new MetricRepository([]), 'app', 'cmp');
    }
}
