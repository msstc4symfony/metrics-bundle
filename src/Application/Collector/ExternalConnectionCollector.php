<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Application\Collector;

use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Application\Port\MetricRecorderInterface;

/**
 * @internal
 */
final readonly class ExternalConnectionCollector
{
    public function __construct(
        private MetricRecorderInterface $recorder,
    ) {
    }

    public function incHTTPConnectionRequest(string $method, string $host, string $path): void
    {
        $this->recorder->increment(BuiltInMetric::HTTP_CONNECTION_REQUEST, [$method, $host, $path]);
    }

    public function incHTTPConnectionResponse(string $method, string $host, string $path, int $status): void
    {
        $this->recorder->increment(BuiltInMetric::HTTP_CONNECTION_RESPONSE, [$method, $host, $path, $status]);
    }

    public function setHTTPConnectionDuration(string $method, string $host, string $path, float $duration): void
    {
        $this->recorder->observe(BuiltInMetric::HTTP_CONNECTION_DURATION_HISTOGRAM_SECONDS, $duration, [$method, $host, $path]);
    }
}
