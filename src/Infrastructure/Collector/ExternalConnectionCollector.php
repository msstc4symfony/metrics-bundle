<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Collector;

use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;

final class ExternalConnectionCollector extends AbstractCollector
{
    public function incHTTPConnectionRequest(string $method, string $host, string $path): void
    {
        $this->incCounter(MetricLabelEnum::HTTP_CONNECTION_REQUEST, [$method, $host, $path]);
    }

    public function incHTTPConnectionResponse(string $method, string $host, string $path, int $status): void
    {
        $this->incCounter(MetricLabelEnum::HTTP_CONNECTION_RESPONSE, [$method, $host, $path, $status]);
    }

    public function setHTTPConnectionDuration(string $method, string $host, string $path, float $duration): void
    {
        $this->observeHistogram(MetricLabelEnum::HTTP_CONNECTION_DURATION_HISTOGRAM_SECONDS, $duration, [$method, $host, $path]);
    }
}
