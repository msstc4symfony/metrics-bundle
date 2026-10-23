<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\Cache;

/**
 * A reversible service callback: the early-expiration dispatcher sends only those to the bus.
 */
final class EarlyComputer
{
    /** @var non-negative-int */
    public int $computations = 0;

    public function __invoke(): string
    {
        $this->computations++;

        return 'value ' . $this->computations;
    }
}
