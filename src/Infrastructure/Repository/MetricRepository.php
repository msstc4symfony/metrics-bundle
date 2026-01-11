<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Repository;

use MaxShamaev\MetricsBundle\Infrastructure\Entity\Label;
use MaxShamaev\MetricsBundle\Infrastructure\Entity\Metric;
use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnumInterface;
use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelTypeEnum;

class MetricRepository implements MetricRepositoryInterface
{
    /**
     * @param iterable<MetricLabelEnumInterface> $metricList
     */
    public function __construct(
        private readonly iterable $metricList,
    ) {
    }

    /**
     * @return Metric[]
     */
    public function findAll(): array
    {
        $result = [];

        foreach ($this->metricList as $label) {
            $result[] = $this->find($label);
        }

        return $result;
    }

    public function find(MetricLabelEnumInterface $label): Metric
    {
        return new Metric(
            $label,
            $label->getType(),
            $label->getDescription(),
            $this->getLabels($label),
            $label->getBatches(),
        );
    }

    /**
     * @return Label[]
     */
    private function getLabels(MetricLabelEnumInterface $label): array
    {
        return array_merge(
            [
                new Label('application', MetricLabelTypeEnum::STRING, 'Application name'),
                new Label('component', MetricLabelTypeEnum::STRING, 'Application component name'),
                new Label('container', MetricLabelTypeEnum::STRING, 'Container / pod ID'),
            ],
            $label->getLabels(),
        );
    }
}
