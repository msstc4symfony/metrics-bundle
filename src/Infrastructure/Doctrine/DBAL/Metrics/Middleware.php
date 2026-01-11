<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;
use MaxShamaev\MetricsBundle\Infrastructure\Collector\DoctrineConnectionCollector;

final class Middleware implements MiddlewareInterface
{
    public function __construct(
        private readonly DoctrineConnectionCollector $collector,
    ) {
    }

    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new Driver($driver, $this->collector);
    }
}
