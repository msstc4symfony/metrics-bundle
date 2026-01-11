<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Entity;

use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnumInterface;
use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricTypeEnum;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class Metric
{
    /**
     * @param Label[] $labels
     * @param float[] $batches
     */
    public function __construct(
        public readonly MetricLabelEnumInterface $name,
        public readonly MetricTypeEnum $type,
        public readonly string $description,
        public readonly array $labels,
        public readonly array $batches,
    ) {
    }

    /**
     * @return string[]
     */
    public function getLabelNames(): array
    {
        return array_map(static fn (Label $label): string => $label->name, $this->labels);
    }
}
