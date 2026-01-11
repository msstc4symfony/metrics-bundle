<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics;

use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Statement as DriverStatement;
use MaxShamaev\MetricsBundle\Infrastructure\Collector\DoctrineConnectionCollector;

final class Connection extends AbstractConnectionMiddleware
{
    /** @internal This connection can be only instantiated by its driver. */
    public function __construct(
        ConnectionInterface $connection,
        private readonly DoctrineConnectionCollector $collector,
        private readonly string $connectionName,
    ) {
        parent::__construct($connection);
    }

    public function prepare(string $sql): DriverStatement
    {
        return new Statement(
            parent::prepare($sql),
            $this->collector,
            $this->connectionName,
            $sql,
        );
    }
}
