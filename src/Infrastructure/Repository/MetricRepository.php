<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Repository;

use MaxShamaev\MetricsBundle\Infrastructure\Entity\Label;
use MaxShamaev\MetricsBundle\Infrastructure\Entity\Metric;
use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnumInterface;
use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelTypeEnum;
use Override;

final class MetricRepository implements MetricRepositoryInterface
{
    /**
     * @var array<int, Metric>
     */
    private array $cache = [];

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
    #[Override]
    public function findAll(): array
    {
        $result = [];

        foreach ($this->metricList as $label) {
            $result[] = $this->find($label);
        }

        return $result;
    }

    #[Override]
    public function find(MetricLabelEnumInterface $label): Metric
    {
        return $this->cache[spl_object_id($label)] ??= new Metric(
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
            ],
            $label->getLabels(),
        );
    }
}
