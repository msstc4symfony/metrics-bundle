<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics;

use Doctrine\Bundle\DoctrineBundle\Middleware\ConnectionNameAwareInterface;
use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;
use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\DoctrineConnectionCollector;
use Override;

/**
 * Labels queries with the DoctrineBundle connection name. DoctrineBundle's MiddlewaresPass
 * clones the definition per connection and calls setConnectionName() on each clone.
 */
final class NamedConnectionMiddleware implements MiddlewareInterface, ConnectionNameAwareInterface
{
    private ?string $connectionName = null;

    public function __construct(
        private readonly DoctrineConnectionCollector $collector,
    ) {
    }

    #[Override]
    public function setConnectionName(string $name): void
    {
        $this->connectionName = $name;
    }

    #[Override]
    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new Driver($driver, $this->collector, $this->connectionName);
    }
}
