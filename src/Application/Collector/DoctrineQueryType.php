<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Application\Collector;

enum DoctrineQueryType: string
{
    case SELECT = 'select';
    case INSERT = 'insert';
    case UPDATE = 'update';
    case DELETE = 'delete';
    case OTHER = 'other';
}
