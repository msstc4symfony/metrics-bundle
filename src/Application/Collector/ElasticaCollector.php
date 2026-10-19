<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Application\Collector;

use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Application\Port\MetricRecorderInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * @internal
 */
#[Autoconfigure(tags: ['container.service_initializer'], public: true)]
final readonly class ElasticaCollector
{
    public function __construct(
        private MetricRecorderInterface $recorder,
    ) {
    }

    public function incRequestSuccess(string $method, string $path): void
    {
        $this->recorder->increment(BuiltInMetric::ELASTICA_REQUEST_SUCCESS, [$method, $path]);
    }

    public function incRequestFailed(string $method, string $path): void
    {
        $this->recorder->increment(BuiltInMetric::ELASTICA_REQUEST_FAILED, [$method, $path]);
    }

    public function setRequestDuration(string $method, string $path, float $duration): void
    {
        $this->recorder->observe(BuiltInMetric::ELASTICA_REQUEST_DURATION_HISTOGRAM_SECONDS, $duration, [$method, $path]);
    }
}
