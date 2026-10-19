<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Application\Metric;

enum MessengerMessageStatus: string
{
    case HANDLED = 'handled';
    case FAILED = 'failed';
    case RETRIED = 'retried';
}
