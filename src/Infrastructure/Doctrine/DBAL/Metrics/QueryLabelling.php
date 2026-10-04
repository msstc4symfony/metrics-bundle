<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics;

/** @internal */
interface QueryLabelling
{
    public function label(string $sql): QueryLabels;
}
