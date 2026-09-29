<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Collector;

use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;

final class RequestCollector extends AbstractCollector
{
    private const string UNKNOWN_ROUTE = 'unknown';

    public function incRequestTotal(string $method, ?string $route): void
    {
        $this->incCounter(MetricLabelEnum::HTTP_REQUEST, [$method, $route ?? self::UNKNOWN_ROUTE]);
    }

    public function incResponseTotal(string $method, ?string $route, int $statusCode): void
    {
        $this->incCounter(MetricLabelEnum::HTTP_RESPONSE, [$method, $route ?? self::UNKNOWN_ROUTE, $statusCode]);
    }

    public function setRequestDuration(string $method, ?string $route, float $duration): void
    {
        $this->observeHistogram(
            MetricLabelEnum::REQUEST_DURATION_HISTOGRAM_SECONDS,
            $duration,
            [$method, $route ?? self::UNKNOWN_ROUTE],
        );
    }

    public function setRequestDurationSummary(string $method, ?string $route, float $duration): void
    {
        $this->observeSummary(
            MetricLabelEnum::REQUEST_DURATION_SUMMARY_SECONDS,
            $duration,
            [$method, $route ?? self::UNKNOWN_ROUTE],
        );
    }
}
