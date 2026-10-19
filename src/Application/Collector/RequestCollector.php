<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Application\Collector;

use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Application\Port\MetricRecorderInterface;

/**
 * @internal
 */
final readonly class RequestCollector
{
    private const UNKNOWN_ROUTE = 'unknown';

    public function __construct(
        private MetricRecorderInterface $recorder,
    ) {
    }

    public function incRequestTotal(string $method, ?string $route): void
    {
        $this->recorder->increment(BuiltInMetric::HTTP_REQUEST, [$method, $route ?? self::UNKNOWN_ROUTE]);
    }

    public function incResponseTotal(string $method, ?string $route, int $statusCode): void
    {
        $this->recorder->increment(BuiltInMetric::HTTP_RESPONSE, [$method, $route ?? self::UNKNOWN_ROUTE, $statusCode]);
    }

    public function setRequestDuration(string $method, ?string $route, float $duration): void
    {
        $this->recorder->observe(
            BuiltInMetric::REQUEST_DURATION_HISTOGRAM_SECONDS,
            $duration,
            [$method, $route ?? self::UNKNOWN_ROUTE],
        );
    }

    public function setRequestDurationSummary(string $method, ?string $route, float $duration): void
    {
        $this->recorder->observe(
            BuiltInMetric::REQUEST_DURATION_SUMMARY_SECONDS,
            $duration,
            [$method, $route ?? self::UNKNOWN_ROUTE],
        );
    }
}
