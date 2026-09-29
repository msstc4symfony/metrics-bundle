<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Enum;

enum MetricTypeEnum: string
{
    case COUNTER = 'counter';
    case HISTOGRAM = 'histogram';
    case SUMMARY = 'summary';
    case GAUGE = 'gauge';
}
