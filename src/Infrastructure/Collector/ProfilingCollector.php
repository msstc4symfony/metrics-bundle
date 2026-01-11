<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Collector;

use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Throwable;

class ProfilingCollector extends AbstractCollector
{
    use LoggerCollectorTrait;

    public function setProfilingSpanDuration(string $message, float $duration): void
    {
        $metric = $this->repository->find(MetricLabelEnum::PROFILING_SPAN_DURATION_HISTOGRAM_SECONDS);

        try {
            $histogram = $this->registry->getOrRegisterHistogram(
                $this->namespace,
                $metric->name->value,
                $metric->description,
                $metric->getLabelNames(),
                $metric->batches,
            );

            $message = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', strtolower($message));

            $histogram->observe($duration, $this->prepareLabelValues([$message]));
        } catch (Throwable $e) {
            $this->processException($e, $metric->name->value);
        }
    }
}
