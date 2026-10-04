<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Infrastructure\Collector;

use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\ExternalConnectionCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepository;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;

final class ExternalConnectionCollectorTest extends CollectorTestCase
{
    public function testIncHTTPConnectionRequest(): void
    {
        $this->build()->incHTTPConnectionRequest('GET', 'api.example.com', '/v1/orders');

        self::assertSame(
            [['app', 'cmp', 'GET', 'api.example.com', '/v1/orders']],
            RegistrySamples::labels($this->registry, MetricLabelEnum::HTTP_CONNECTION_REQUEST),
        );
    }

    public function testIncHTTPConnectionResponseLabelsByStatusCode(): void
    {
        $this->build()->incHTTPConnectionResponse('GET', 'api.example.com', '/v1/orders', 502);

        self::assertSame(
            [['app', 'cmp', 'GET', 'api.example.com', '/v1/orders', '502']],
            RegistrySamples::labels($this->registry, MetricLabelEnum::HTTP_CONNECTION_RESPONSE),
        );
    }

    public function testSetHTTPConnectionDurationObserved(): void
    {
        $this->build()->setHTTPConnectionDuration('GET', 'api.example.com', '/v1/orders', 0.12);

        self::assertTrue(RegistrySamples::exists($this->registry, MetricLabelEnum::HTTP_CONNECTION_DURATION_HISTOGRAM_SECONDS));
    }

    private function build(): ExternalConnectionCollector
    {
        return new ExternalConnectionCollector($this->registry, new MetricRepository([]), 'app', 'cmp');
    }
}
