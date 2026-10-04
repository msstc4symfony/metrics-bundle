<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Enum;

enum MessengerMessageStatusEnum: string
{
    case HANDLED = 'handled';
    case FAILED = 'failed';
    case RETRIED = 'retried';
}
