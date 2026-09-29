<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Enum;

use BackedEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Entity\Label;

interface MetricLabelEnumInterface extends BackedEnum
{
    public function getType(): MetricTypeEnum;

    public function getDescription(): string;

    /**
     * @return Label[]
     */
    public function getLabels(): array;

    /**
     * @return float[]
     */
    public function getBatches(): array;
}
