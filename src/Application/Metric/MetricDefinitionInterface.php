<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Application\Metric;

use BackedEnum;

interface MetricDefinitionInterface extends BackedEnum
{
    public function getType(): MetricType;

    public function getDescription(): string;

    /**
     * @return list<Label>
     */
    public function getLabels(): array;

    /**
     * @return list<float>
     */
    public function getBatches(): array;
}
