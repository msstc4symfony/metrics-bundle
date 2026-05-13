<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Collector;

use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;

final class InfoCollector extends AbstractCollector
{
    public function setMetric(MetricLabelEnum $metricType, float|int $value): void
    {
        $this->setGauge($metricType, $value);
    }
}
