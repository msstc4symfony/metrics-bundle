<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Application;

final readonly class LogChannel
{
    /** Excluded from the Monolog decoration: a failure to record must not record an error metric. */
    public const NAME = 'metrics_bundle';
}
