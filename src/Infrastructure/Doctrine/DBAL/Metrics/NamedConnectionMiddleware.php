<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;
use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\DoctrineConnectionCollector;
use Override;

/**
 * Labels the queries of one connection with its name ("connection" label of the DBAL metrics).
 */
final readonly class NamedConnectionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private DoctrineConnectionCollector $collector,
        private string $connectionName,
    ) {
    }

    #[Override]
    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new Driver($driver, $this->collector, $this->connectionName);
    }
}
