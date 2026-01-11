<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Collector;

use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Throwable;

class InfoCollector extends AbstractCollector
{
    use LoggerCollectorTrait;

    public function setMetric(MetricLabelEnum $metricType, float|int $value): void
    {
        $metric = $this->repository->find($metricType);

        try {
            $gauge = $this->registry->getOrRegisterGauge(
                $this->namespace,
                $metric->name->value,
                $metric->description,
                $metric->getLabelNames(),
            );

            $gauge->set($value, $this->prepareLabelValues());
        } catch (Throwable $e) {
            $this->processException($e, $metric->name->value);
        }
    }
}
