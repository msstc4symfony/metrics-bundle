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
    private const string IDENTIFIER = '(?:\w+|"[^"]+"|`[^`]+`|\[[^\]]+\])';

    // Optional schema prefix is matched but not captured: "public.users" labels as "users".
    private const string TABLE = '(?:' . self::IDENTIFIER . '\.)?(' . self::IDENTIFIER . ')';

    private const string NOISE = "/'(?:[^'\\\\]|\\\\.|'')*'|--[^\\n]*|\\/\\*.*?(?:\\*\\/|\\z)/s";

    private const string PARENTHESISED = '/\((?:[^()]++|(?R))*+\)/';

    // A keyword is neither part of a longer word ("fromage") nor a qualified column ("t.from").
    private const string KEYWORD_START = '(?<![\w.])';

    // Group 1 is a derived table's placeholder "(#n)"; every other group is a table name.
    private const string STATEMENT = '/' . self::KEYWORD_START . '(?:'
        . 'SELECT\b.*?' . self::KEYWORD_START . 'FROM\b\s*(?:\(#(\d+)\)|' . self::TABLE . ')'
        . '|INSERT\s+INTO\s+' . self::TABLE
        . '|DELETE\s+FROM\s+' . self::TABLE
        . '|UPDATE\s+' . self::TABLE
        . ')/Sis';

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
        // Comments and string literals may contain "from <word>"; they carry no structure.
        $sql = preg_replace_callback(
            self::NOISE,
            static fn (array $match): string => str_starts_with($match[0], "'") ? "''" : ' ',
            substr($sql, 0, self::MAX_PARSED_SQL_LENGTH),
        );

        return $sql === null ? null : $this->findTable($sql);
    }

    /**
     * Searches the top level only: parenthesised groups (function arguments, subqueries, column lists)
     * are folded into "(#n)" placeholders, so a FROM inside them is never mistaken for the query's own.
     * A derived table ("FROM (SELECT ...) t") is searched recursively.
     */
    private function findTable(string $sql): ?string
    {
        $groups = [];
        $topLevel = preg_replace_callback(
            self::PARENTHESISED,
            static function (array $match) use (&$groups): string {
                $groups[] = substr($match[0], 1, -1);

                return '(#' . (\count($groups) - 1) . ')';
            },
            $sql,
        );

        if ($topLevel === null || preg_match(self::STATEMENT, $topLevel, $match) !== 1) {
            return null;
        }

        if (isset($match[1]) && $match[1] !== '') {
            $inner = $groups[(int) $match[1]] ?? null;

            return $inner === null ? null : $this->findTable($inner);
        }

        foreach (\array_slice($match, 2) as $table) {
            if ($table !== '') {
                return trim($table, '"`[]');
            }
        }

        return null;
    }
}
