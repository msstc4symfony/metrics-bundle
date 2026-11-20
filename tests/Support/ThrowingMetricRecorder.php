<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Support;

use Msstc4Symfony\MetricsBundle\Application\Metric\MetricDefinitionInterface;
use Msstc4Symfony\MetricsBundle\Application\Port\MetricRecorderInterface;
use Override;
use RuntimeException;

/**
 * A replacement recorder that breaks the port contract: every write throws.
 */
final class ThrowingMetricRecorder implements MetricRecorderInterface
{
    #[Override]
    public function increment(MetricDefinitionInterface $metric, array $labels = []): void
    {
        throw new RuntimeException('recorder failed');
    }

    #[Override]
    public function set(MetricDefinitionInterface $metric, float|int $value, array $labels = []): void
    {
        throw new RuntimeException('recorder failed');
    }

    #[Override]
    public function observe(MetricDefinitionInterface $metric, float $value, array $labels = []): void
    {
        throw new RuntimeException('recorder failed');
    }
}
