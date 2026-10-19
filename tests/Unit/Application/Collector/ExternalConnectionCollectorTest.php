<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Application\Collector;

use Msstc4Symfony\MetricsBundle\Application\Collector\ExternalConnectionCollector;
use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ExternalConnectionCollector::class)]
final class ExternalConnectionCollectorTest extends RecorderTestCase
{
    public function testIncHTTPConnectionRequest(): void
    {
        $this->build()->incHTTPConnectionRequest('GET', 'api.example.com', '/v1/orders');

        self::assertSame([['increment', BuiltInMetric::HTTP_CONNECTION_REQUEST, null, ['GET', 'api.example.com', '/v1/orders']]], $this->recorder->calls);
    }

    public function testIncHTTPConnectionResponseLabelsByStatusCode(): void
    {
        $this->build()->incHTTPConnectionResponse('GET', 'api.example.com', '/v1/orders', 502);

        self::assertSame([['increment', BuiltInMetric::HTTP_CONNECTION_RESPONSE, null, ['GET', 'api.example.com', '/v1/orders', 502]]], $this->recorder->calls);
    }

    public function testSetHTTPConnectionDurationObserved(): void
    {
        $this->build()->setHTTPConnectionDuration('GET', 'api.example.com', '/v1/orders', 0.12);

        self::assertSame([['observe', BuiltInMetric::HTTP_CONNECTION_DURATION_HISTOGRAM_SECONDS, 0.12, ['GET', 'api.example.com', '/v1/orders']]], $this->recorder->calls);
    }

    private function build(): ExternalConnectionCollector
    {
        return new ExternalConnectionCollector($this->recorder);
    }
}
