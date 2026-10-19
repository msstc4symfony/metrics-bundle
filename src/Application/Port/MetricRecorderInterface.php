<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Application\Port;

use Msstc4Symfony\MetricsBundle\Application\Metric\MetricDefinitionInterface;

/**
 * Records a value of a metric. Storage failures never leave an implementation: they are logged instead.
 */
interface MetricRecorderInterface
{
    /**
     * @param list<scalar> $labels values of the definition's own labels, in order
     */
    public function increment(MetricDefinitionInterface $metric, array $labels = []): void;

    /**
     * @param list<scalar> $labels values of the definition's own labels, in order
     */
    public function set(MetricDefinitionInterface $metric, float|int $value, array $labels = []): void;

    /**
     * Histogram or summary, by the definition's type.
     *
     * @param list<scalar> $labels values of the definition's own labels, in order
     */
    public function observe(MetricDefinitionInterface $metric, float $value, array $labels = []): void;
}
