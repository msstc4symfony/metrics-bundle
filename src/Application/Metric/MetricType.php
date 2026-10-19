<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Application\Metric;

enum MetricType: string
{
    case COUNTER = 'counter';
    case HISTOGRAM = 'histogram';
    case SUMMARY = 'summary';
    case GAUGE = 'gauge';
}
