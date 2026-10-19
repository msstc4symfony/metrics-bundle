<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Support;

use Msstc4Symfony\MetricsBundle\Application\Metric\MetricDefinitionInterface;
use Msstc4Symfony\MetricsBundle\Application\Port\MetricRecorderInterface;
use Override;

final class RecordingMetricRecorder implements MetricRecorderInterface
{
    /** @var list<array{string, MetricDefinitionInterface, float|int|null, list<scalar>}> */
    public array $calls = [];

    #[Override]
    public function increment(MetricDefinitionInterface $metric, array $labels = []): void
    {
        $this->calls[] = ['increment', $metric, null, $labels];
    }

    #[Override]
    public function set(MetricDefinitionInterface $metric, float|int $value, array $labels = []): void
    {
        $this->calls[] = ['set', $metric, $value, $labels];
    }

    #[Override]
    public function observe(MetricDefinitionInterface $metric, float $value, array $labels = []): void
    {
        $this->calls[] = ['observe', $metric, $value, $labels];
    }
}
