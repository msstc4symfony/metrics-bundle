<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Collector;

use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(tags: ['container.service_initializer'], public: true)]
final class ElasticaCollector extends AbstractCollector
{
    public function incRequestSuccess(string $method, string $path): void
    {
        $this->incCounter(MetricLabelEnum::ELASTICA_REQUEST_SUCCESS, [$method, $path]);
    }

    public function incRequestFailed(string $method, string $path): void
    {
        $this->incCounter(MetricLabelEnum::ELASTICA_REQUEST_FAILED, [$method, $path]);
    }

    public function setRequestDuration(string $method, string $path, float $duration): void
    {
        $this->observeHistogram(MetricLabelEnum::ELASTICA_REQUEST_DURATION_HISTOGRAM_SECONDS, $duration, [$method, $path]);
    }
}
