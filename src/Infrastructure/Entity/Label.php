<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Entity;

use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelTypeEnum;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class Label
{
    /**
     * @param string[]|int[]|float[] $enums
     */
    public function __construct(
        public readonly string $name,
        public readonly MetricLabelTypeEnum $type,
        public readonly ?string $description = null,
        public readonly array $enums = [],
    ) {
    }
}
