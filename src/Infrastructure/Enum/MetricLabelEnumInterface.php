<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Enum;

use BackedEnum;
use MaxShamaev\MetricsBundle\Infrastructure\Entity\Label;

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
