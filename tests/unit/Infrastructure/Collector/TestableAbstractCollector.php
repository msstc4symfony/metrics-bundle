<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Test\Unit\Infrastructure\Collector;

use MaxShamaev\MetricsBundle\Infrastructure\Collector\AbstractCollector;
use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnumInterface;

final class TestableAbstractCollector extends AbstractCollector
{
    /**
     * @param scalar[] $values
     *
     * @return string[]
     */
    public function exposePrepareLabelValues(array $values = []): array
    {
        return $this->prepareLabelValues($values);
    }

    /**
     * @param scalar[] $labels
     */
    public function callIncCounter(MetricLabelEnumInterface $enum, array $labels = []): void
    {
        $this->incCounter($enum, $labels);
    }

    /**
     * @param scalar[] $labels
     */
    public function callSetGauge(MetricLabelEnumInterface $enum, float|int $value, array $labels = []): void
    {
        $this->setGauge($enum, $value, $labels);
    }

    /**
     * @param scalar[] $labels
     */
    public function callObserveHistogram(MetricLabelEnumInterface $enum, float $value, array $labels = []): void
    {
        $this->observeHistogram($enum, $value, $labels);
    }

    /**
     * @param scalar[] $labels
     */
    public function callObserveSummary(MetricLabelEnumInterface $enum, float $value, array $labels = []): void
    {
        $this->observeSummary($enum, $value, $labels);
    }
}
