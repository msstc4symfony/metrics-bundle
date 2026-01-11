<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Collector;

use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Throwable;

#[Autoconfigure(tags: ['container.service_initializer'], public: true)]
class ElasticaCollector extends AbstractCollector
{
    use LoggerCollectorTrait;

    public function incRequestSuccess(string $method, string $path): void
    {
        $metric = $this->repository->find(MetricLabelEnum::ELASTICA_REQUEST_SUCCESS);

        try {
            $counter = $this->registry->getOrRegisterCounter(
                $this->namespace,
                $metric->name->value,
                $metric->description,
                $metric->getLabelNames(),
            );

            $counter->inc($this->prepareLabelValues([$method, $path]));
        } catch (Throwable $e) {
            $this->processException($e, $metric->name->value);
        }
    }

    public function incRequestFailed(string $method, string $path): void
    {
        $metric = $this->repository->find(MetricLabelEnum::ELASTICA_REQUEST_FAILED);

        try {
            $counter = $this->registry->getOrRegisterCounter(
                $this->namespace,
                $metric->name->value,
                $metric->description,
                $metric->getLabelNames(),
            );

            $counter->inc($this->prepareLabelValues([$method, $path]));
        } catch (Throwable $e) {
            $this->processException($e, $metric->name->value);
        }
    }

    public function setRequestDuration(string $method, string $path, float $duration): void
    {
        $metric = $this->repository->find(MetricLabelEnum::ELASTICA_REQUEST_DURATION_HISTOGRAM_SECONDS);

        try {
            $histogram = $this->registry->getOrRegisterHistogram(
                $this->namespace,
                $metric->name->value,
                $metric->description,
                $metric->getLabelNames(),
                $metric->batches,
            );

            $histogram->observe($duration, $this->prepareLabelValues([$method, $path]));
        } catch (Throwable $e) {
            $this->processException($e, $metric->name->value);
        }
    }
}
