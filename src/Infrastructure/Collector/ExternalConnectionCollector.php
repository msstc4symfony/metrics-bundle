<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Collector;

use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Throwable;

class ExternalConnectionCollector extends AbstractCollector
{
    use LoggerCollectorTrait;

    public function incHTTPConnectionRequest(string $method, string $host, string $path): void
    {
        $metric = $this->repository->find(MetricLabelEnum::HTTP_CONNECTION_REQUEST);

        try {
            $counter = $this->registry->getOrRegisterCounter(
                $this->namespace,
                $metric->name->value,
                $metric->description,
                $metric->getLabelNames(),
            );

            $counter->inc($this->prepareLabelValues([$method, $host, $path]));
        } catch (Throwable $e) {
            $this->processException($e, $metric->name->value);
        }
    }

    public function incHTTPConnectionResponse(string $method, string $host, string $path, int $status): void
    {
        $metric = $this->repository->find(MetricLabelEnum::HTTP_CONNECTION_RESPONSE);

        try {
            $counter = $this->registry->getOrRegisterCounter(
                $this->namespace,
                $metric->name->value,
                $metric->description,
                $metric->getLabelNames(),
            );

            $counter->inc($this->prepareLabelValues([$method, $host, $path, $status]));
        } catch (Throwable $e) {
            $this->processException($e, $metric->name->value);
        }
    }

    public function setHTTPConnectionDuration(string $method, string $host, string $path, float $duration): void
    {
        $metric = $this->repository->find(MetricLabelEnum::HTTP_CONNECTION_DURATION_HISTOGRAM_SECONDS);

        try {
            $histogram = $this->registry->getOrRegisterHistogram(
                $this->namespace,
                $metric->name->value,
                $metric->description,
                $metric->getLabelNames(),
                $metric->batches,
            );

            $histogram->observe($duration, $this->prepareLabelValues([$method, $host, $path]));
        } catch (Throwable $e) {
            $this->processException($e, $metric->name->value);
        }
    }
}
