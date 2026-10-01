<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics;

use Closure;
use Doctrine\DBAL\Driver\Result as ResultInterface;
use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\DoctrineConnectionCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\DoctrineQueryTypeEnum;

/** @internal Shared by the connection and statement middlewares. */
final readonly class QueryMeter
{
    // Optional schema prefix is matched but not captured: "public.users" labels as "users".
    private const string TABLE = '(?:\w+\.)?(\w+)';

    // Bounds regex cost on huge statements (long IN lists, batch inserts); the table name sits near the start.
    private const int MAX_PARSED_SQL_LENGTH = 16_384;

    public function __construct(
        private DoctrineConnectionCollector $collector,
        private string $connectionName,
    ) {
    }

    /**
     * @template TResult of ResultInterface|int|string
     *
     * @param Closure(): TResult $query
     *
     * @return TResult
     */
    public function measure(string $sql, Closure $query): mixed
    {
        $startTime = microtime(true);

        $result = $query();

        $type = $this->assembleType($sql);
        $table = $this->assembleTableName($sql);
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
        $sql = substr($sql, 0, self::MAX_PARSED_SQL_LENGTH);
        // Lazy select list: the first FROM belongs to the outer query, later ones to subqueries.
        $pattern = '/(?:'
            . 'SELECT\s+.+?\s+FROM\s+' . self::TABLE
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
