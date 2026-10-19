<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Application\Metric;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class Metric
{
    /**
     * @var list<string>
     */
    public array $labelNames;

    /**
     * @param list<Label> $labels
     * @param list<float> $batches
     */
    public function __construct(
        public MetricDefinitionInterface $name,
        public MetricType $type,
        public string $description,
        public array $labels,
        public array $batches,
    ) {
        $this->labelNames = array_map(static fn (Label $label): string => $label->name, $labels);
    }
}
