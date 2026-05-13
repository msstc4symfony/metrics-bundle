<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result as ResultInterface;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use MaxShamaev\MetricsBundle\Infrastructure\Collector\DoctrineConnectionCollector;
use MaxShamaev\MetricsBundle\Infrastructure\Enum\DoctrineQueryTypeEnum;

final class Statement extends AbstractStatementMiddleware
{
    /** @internal This statement can be only instantiated by its connection. */
    public function __construct(
        StatementInterface $statement,
        private readonly DoctrineConnectionCollector $collector,
        private readonly string $connectionName,
        private readonly string $sql,
    ) {
        parent::__construct($statement);
    }

    public function execute($params = null): ResultInterface
    {
        $startTime = microtime(true);

        $result = parent::execute($params);

        $type = $this->assembleType($this->sql);
        $table = $this->assembleTableName($this->sql);
        $this->collector->incQueryExecute($this->connectionName, $type, $table);
        $this->collector->setQueryExecuteDuration($this->connectionName, $type, $table, microtime(true) - $startTime);

        return $result;
    }

    private function assembleType(string $sql): DoctrineQueryTypeEnum
    {
        if (preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE)\s+/Si', $sql, $match) !== 1) {
            return DoctrineQueryTypeEnum::OTHER;
        }

        return DoctrineQueryTypeEnum::from($match[1]);
    }

    private function assembleTableName(string $sql): ?string
    {
        $pattern = '/(?:'
            . 'SELECT\s+.+\s+FROM\s+([\w_]+)\s'
            . '|INSERT\s+INTO\s+([\w_]+)\s'
            . '|DELETE\s+FROM\s+([\w_]+)'
            . '|UPDATE\s+([\w_]+)\s'
            . ')/Sis';

        if (preg_match($pattern, $sql, $match) !== 1) {
            return null;
        }

        return $match[1];
    }
}
