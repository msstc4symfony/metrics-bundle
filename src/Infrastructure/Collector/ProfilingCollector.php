<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Collector;

use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;

/**
 * @deprecated since 1.2, use msstc4symfony/metrics-bridge-profiling; removed in 2.0
 */
final class ProfilingCollector extends AbstractCollector
{
    public function setProfilingSpanDuration(string $message, float $duration): void
    {
        $normalized = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', strtolower($message)) ?? $message;

        $this->observeHistogram(MetricLabelEnum::PROFILING_SPAN_DURATION_HISTOGRAM_SECONDS, $duration, [$normalized]);
    }
}
