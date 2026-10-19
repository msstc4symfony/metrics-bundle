<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Application\Metric;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class Label
{
    /**
     * @param list<string>|list<int>|list<float> $enums
     */
    public function __construct(
        public string $name,
        public LabelType $type,
        public ?string $description = null,
        public array $enums = [],
    ) {
    }
}
