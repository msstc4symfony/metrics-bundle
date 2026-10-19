<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Application\Metric;

use Override;

final class MetricRepository implements MetricRepositoryInterface
{
    /**
     * @var array<int, Metric>
     */
    private array $cache = [];

    /**
     * @param iterable<MetricDefinitionInterface> $metricList
     */
    public function __construct(
        private readonly iterable $metricList,
    ) {
    }

    /**
     * @return list<Metric>
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
    public function find(MetricDefinitionInterface $label): Metric
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
     * @return list<Label>
     */
    private function getLabels(MetricDefinitionInterface $label): array
    {
        return array_merge(
            [
                new Label('application', LabelType::STRING, 'Application name'),
                new Label('component', LabelType::STRING, 'Application component name'),
            ],
            $label->getLabels(),
        );
    }
}
