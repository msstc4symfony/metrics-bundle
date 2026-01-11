<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Enum;

enum DoctrineQueryTypeEnum: string
{
    case SELECT = 'select';
    case INSERT = 'insert';
    case UPDATE = 'update';
    case DELETE = 'delete';
    case OTHER = 'other';
}
