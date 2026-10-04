<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Collector;

use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;

final class InfoCollector extends AbstractCollector
{
    public function setMetric(MetricLabelEnum $metricType, float|int $value): void
    {
        $this->setGauge($metricType, $value);
    }
}
