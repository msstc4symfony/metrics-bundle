<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result as ResultInterface;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\DoctrineConnectionCollector;
use Override;

final class Statement extends AbstractStatementMiddleware
{
    private readonly QueryMeter $meter;

    /** @internal This statement can be only instantiated by its connection. */
    public function __construct(
        StatementInterface $statement,
        DoctrineConnectionCollector $collector,
        string $connectionName,
        private readonly string $sql,
    ) {
        parent::__construct($statement);
        $this->meter = new QueryMeter($collector, $connectionName);
    }

    /**
     * DBAL 3 passes bound values here; DBAL 4 dropped the parameter. Forwarding the
     * received arguments as-is keeps both majors working.
     *
     * @param array<array-key, mixed>|null $params
     */
    #[Override]
    public function execute(mixed $params = null): ResultInterface
    {
        $arguments = func_get_args();

        return $this->meter->measure($this->sql, fn (): ResultInterface => parent::execute(...$arguments));
    }
}
