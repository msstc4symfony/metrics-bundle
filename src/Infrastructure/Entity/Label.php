<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Entity;

use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelTypeEnum;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class Label
{
    /**
     * @param string[]|int[]|float[] $enums
     */
    public function __construct(
        public string $name,
        public MetricLabelTypeEnum $type,
        public ?string $description = null,
        public array $enums = [],
    ) {
    }
}
