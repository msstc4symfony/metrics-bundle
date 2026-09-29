<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;
use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\DoctrineConnectionCollector;
use Override;

final readonly class Middleware implements MiddlewareInterface
{
    public function __construct(
        private DoctrineConnectionCollector $collector,
    ) {
    }

    #[Override]
    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new Driver($driver, $this->collector);
    }
}
