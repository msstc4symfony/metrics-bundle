<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Test\Unit\Infrastructure\Collector;

use MaxShamaev\MetricsBundle\Infrastructure\Collector\ExternalConnectionCollector;
use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use MaxShamaev\MetricsBundle\Infrastructure\Repository\MetricRepository;

final class ExternalConnectionCollectorTest extends CollectorTestCase
{
    public function testIncHTTPConnectionRequest(): void
    {
        $this->build()->incHTTPConnectionRequest('GET', 'api.example.com', '/v1/orders');

        self::assertSame(
            [['app', 'cmp', 'GET', 'api.example.com', '/v1/orders']],
            $this->labelValuesFor('symfony_' . MetricLabelEnum::HTTP_CONNECTION_REQUEST->value),
        );
    }

    public function testIncHTTPConnectionResponseLabelsByStatusCode(): void
    {
        $this->build()->incHTTPConnectionResponse('GET', 'api.example.com', '/v1/orders', 502);

        self::assertSame(
            [['app', 'cmp', 'GET', 'api.example.com', '/v1/orders', '502']],
            $this->labelValuesFor('symfony_' . MetricLabelEnum::HTTP_CONNECTION_RESPONSE->value),
        );
    }

    public function testSetHTTPConnectionDurationObserved(): void
    {
        $this->build()->setHTTPConnectionDuration('GET', 'api.example.com', '/v1/orders', 0.12);

        self::assertTrue($this->familyExists('symfony_' . MetricLabelEnum::HTTP_CONNECTION_DURATION_HISTOGRAM_SECONDS->value));
    }

    private function build(): ExternalConnectionCollector
    {
        return new ExternalConnectionCollector($this->registry, new MetricRepository([]), 'app', 'cmp');
    }
}
