<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Collector;

use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Throwable;

class RequestCollector extends AbstractCollector
{
    use LoggerCollectorTrait;

    public function incRequestTotal(string $method, ?string $route): void
    {
        $metric = $this->repository->find(MetricLabelEnum::HTTP_REQUEST);

        try {
            $counter = $this->registry->getOrRegisterCounter(
                $this->namespace,
                $metric->name->value,
                $metric->description,
                $metric->getLabelNames(),
            );

            $counter->inc($this->prepareLabelValues([$method, $route ?? 'unknown']));
        } catch (Throwable $e) {
            $this->processException($e, $metric->name->value);
        }
    }

    public function incResponseTotal(string $method, ?string $route, int $statusCode): void
    {
        $metric = $this->repository->find(MetricLabelEnum::HTTP_RESPONSE);

        try {
            $counter = $this->registry->getOrRegisterCounter(
                $this->namespace,
                $metric->name->value,
                $metric->description,
                $metric->getLabelNames(),
            );

            $counter->inc($this->prepareLabelValues([$method, $route ?? 'unknown', $statusCode]));
        } catch (Throwable $e) {
            $this->processException($e, $metric->name->value);
        }
    }

    public function setRequestDuration(string $method, ?string $route, float $duration): void
    {
        $metric = $this->repository->find(MetricLabelEnum::REQUEST_DURATION_HISTOGRAM_SECONDS);

        try {
            $histogram = $this->registry->getOrRegisterHistogram(
                $this->namespace,
                $metric->name->value,
                $metric->description,
                $metric->getLabelNames(),
                $metric->batches,
            );

            $histogram->observe($duration, $this->prepareLabelValues([$method, $route ?? 'unknown']));
        } catch (Throwable $e) {
            $this->processException($e, $metric->name->value);
        }
    }

    public function setRequestDurationSummary(string $method, ?string $route, float $duration): void
    {
        $metric = $this->repository->find(MetricLabelEnum::REQUEST_DURATION_SUMMARY_SECONDS);

        try {
            $summary = $this->registry->getOrRegisterSummary(
                $this->namespace,
                $metric->name->value,
                $metric->description,
                $metric->getLabelNames(),
                quantiles: [0.5, 0.9, 0.95, 0.99],
            );

            $summary->observe($duration, $this->prepareLabelValues([$method, $route ?? 'unknown']));
        } catch (Throwable $e) {
            $this->processException($e, $metric->name->value);
        }
    }
}
