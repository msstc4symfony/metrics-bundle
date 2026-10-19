<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Application\Collector;

use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Application\Port\MetricRecorderInterface;

/**
 * @internal
 */
final readonly class InfoCollector
{
    public function __construct(
        private MetricRecorderInterface $recorder,
    ) {
    }

    public function setMetric(BuiltInMetric $metric, float|int $value): void
    {
        $this->recorder->set($metric, $value);
    }
}
