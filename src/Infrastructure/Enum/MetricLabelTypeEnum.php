<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Enum;

enum MetricLabelTypeEnum: string
{
    case STRING = 'string';
    case INTEGER = 'integer';
    case FLOAT = 'float';
    case ENUM = 'enum';
}
