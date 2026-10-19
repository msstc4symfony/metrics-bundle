<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Application\Collector;

use Msstc4Symfony\MetricsBundle\Application\Collector\RequestCollector;
use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(RequestCollector::class)]
final class RequestCollectorTest extends RecorderTestCase
{
    public function testIncRequestTotal(): void
    {
        $this->build()->incRequestTotal('GET', 'order_show');

        self::assertSame([['increment', BuiltInMetric::HTTP_REQUEST, null, ['GET', 'order_show']]], $this->recorder->calls);
    }

    public function testIncRequestTotalNullRouteFallsBackToUnknown(): void
    {
        $this->build()->incRequestTotal('GET', null);

        self::assertSame([['increment', BuiltInMetric::HTTP_REQUEST, null, ['GET', 'unknown']]], $this->recorder->calls);
    }

    public function testIncResponseTotalLabelsByStatusCode(): void
    {
        $this->build()->incResponseTotal('GET', 'order_show', 404);

        self::assertSame([['increment', BuiltInMetric::HTTP_RESPONSE, null, ['GET', 'order_show', 404]]], $this->recorder->calls);
    }

    public function testSetRequestDurationObservesTheHistogram(): void
    {
        $this->build()->setRequestDuration('GET', 'order_show', 0.12);

        self::assertSame([['observe', BuiltInMetric::REQUEST_DURATION_HISTOGRAM_SECONDS, 0.12, ['GET', 'order_show']]], $this->recorder->calls);
    }

    public function testSetRequestDurationSummaryObservesTheSummary(): void
    {
        $this->build()->setRequestDurationSummary('GET', 'order_show', 0.18);

        self::assertSame([['observe', BuiltInMetric::REQUEST_DURATION_SUMMARY_SECONDS, 0.18, ['GET', 'order_show']]], $this->recorder->calls);
    }

    public function testSetRequestDurationNullRouteFallsBack(): void
    {
        $this->build()->setRequestDuration('GET', null, 0.1);

        self::assertSame([['observe', BuiltInMetric::REQUEST_DURATION_HISTOGRAM_SECONDS, 0.1, ['GET', 'unknown']]], $this->recorder->calls);
    }

    private function build(): RequestCollector
    {
        return new RequestCollector($this->recorder);
    }
}
