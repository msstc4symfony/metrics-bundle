<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Application\Port;

use InvalidArgumentException;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricDefinitionInterface;

/**
 * Records counts aggregated in memory with one storage write per series. Storage failures never leave an
 * implementation: they are logged instead.
 */
interface MetricBatchRecorderInterface
{
    /**
     * @param int $amount added to the counter, at least 1
     * @param list<scalar> $labels values of the definition's own labels, in order
     *
     * @throws InvalidArgumentException when the metric is not a counter or the amount is below 1
     */
    public function incrementBy(MetricDefinitionInterface $metric, int $amount, array $labels = []): void;
}
