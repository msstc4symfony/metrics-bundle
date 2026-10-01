<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result as ResultInterface;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\DoctrineConnectionCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\DoctrineQueryTypeEnum;
use Override;

final class Statement extends AbstractStatementMiddleware
{
    // Optional schema prefix is matched but not captured: "public.users" labels as "users".
    private const string TABLE = '(?:\w+\.)?(\w+)';

    /** @internal This statement can be only instantiated by its connection. */
    public function __construct(
        StatementInterface $statement,
        private readonly DoctrineConnectionCollector $collector,
        private readonly string $connectionName,
        private readonly string $sql,
    ) {
        parent::__construct($statement);
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
        $startTime = microtime(true);

        $result = parent::execute(...func_get_args());

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

        return DoctrineQueryTypeEnum::from(strtolower($match[1]));
    }

    private function assembleTableName(string $sql): ?string
    {
        $pattern = '/(?:'
            . 'SELECT\s+.+\s+FROM\s+' . self::TABLE
            . '|INSERT\s+INTO\s+' . self::TABLE
            . '|DELETE\s+FROM\s+' . self::TABLE
            . '|UPDATE\s+' . self::TABLE
            . ')/Sis';

        if (preg_match($pattern, $sql, $match) !== 1) {
            return null;
        }

        // Alternation: only one capture group will be non-empty; pick the first match.
        foreach ([1, 2, 3, 4] as $group) {
            if (isset($match[$group]) && $match[$group] !== '') {
                return $match[$group];
            }
        }

        return null;
    }
}
