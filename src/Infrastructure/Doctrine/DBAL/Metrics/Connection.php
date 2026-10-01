<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics;

use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result as ResultInterface;
use Doctrine\DBAL\Driver\Statement as DriverStatement;
use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\DoctrineConnectionCollector;
use Override;

final class Connection extends AbstractConnectionMiddleware
{
    private readonly QueryMeter $meter;

    /** @internal This connection can be only instantiated by its driver. */
    public function __construct(
        ConnectionInterface $connection,
        DoctrineConnectionCollector $collector,
        string $connectionName,
    ) {
        parent::__construct($connection);
        $this->meter = new QueryMeter($collector, $connectionName);
    }

    #[Override]
    public function prepare(string $sql): DriverStatement
    {
        return new Statement(parent::prepare($sql), $this->meter, $sql);
    }

    /** DBAL sends queries without bound parameters here, bypassing prepare(). */
    #[Override]
    public function query(string $sql): ResultInterface
    {
        return $this->meter->measure($sql, fn (): ResultInterface => parent::query($sql));
    }

    /**
     * DBAL 3 declares int, DBAL 4 int|string; int satisfies both. DBAL 4 returns a string
     * only for row counts beyond PHP_INT_MAX.
     */
    #[Override]
    public function exec(string $sql): int
    {
        $affected = $this->meter->measure($sql, fn (): int|string => parent::exec($sql));

        return is_int($affected) ? $affected : (int) $affected;
    }
}
