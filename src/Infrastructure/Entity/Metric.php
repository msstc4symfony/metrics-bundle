<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Entity;

use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnumInterface;
use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricTypeEnum;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class Metric
{
    /**
     * @var string[]
     */
    public array $labelNames;

    /**
     * @param Label[] $labels
     * @param float[] $batches
     */
    public function __construct(
        public MetricLabelEnumInterface $name,
        public MetricTypeEnum $type,
        public string $description,
        public array $labels,
        public array $batches,
    ) {
        $this->labelNames = array_map(static fn (Label $label): string => $label->name, $labels);
    }
}
