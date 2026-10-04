<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics;

use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\DoctrineQueryTypeEnum;
use Override;

/**
 * Derives the "type" and "table" labels from SQL text. Results are cached per SQL because prepared
 * statements repeat and parsing is far more expensive than a lookup.
 *
 * @internal
 */
final class QueryLabeller implements QueryLabelling
{
    private const string IDENTIFIER = '(?:\w+|"(?:[^"]|"")+"|`(?:[^`]|``)+`|\[[^\]]+\])';

    // Schema and catalog prefixes are matched but not captured: "db.public.users" labels as "users".
    private const string TABLE = '(?:' . self::IDENTIFIER . '\.)*+(' . self::IDENTIFIER . ')';

    private const string NOISE = "/'(?:[^'\\\\]|\\\\.|'')*'|--[^\\n]*|\\/\\*.*?(?:\\*\\/|\\z)/s";

    private const string PARENTHESISED = '/\((?:[^()]++|(?R))*+\)/';

    // A keyword is neither part of a longer word ("fromage") nor a qualified column ("t.from").
    private const string KEYWORD_START = '(?<![\w.])';

    // Group 1 is a derived table's placeholder "(#n)"; every other group is a table name.
    private const string STATEMENT = '/' . self::KEYWORD_START . '(?:'
        . 'SELECT\b.*?' . self::KEYWORD_START . 'FROM\b\s*(?:(?:ONLY|LATERAL)\b\s*)?(?:\(#(\d+)\)|' . self::TABLE . ')'
        . '|INSERT\s+INTO\s+' . self::TABLE
        . '|DELETE\s+FROM\s+(?:ONLY\b\s*)?' . self::TABLE
        . '|UPDATE\s+(?:ONLY\b\s*)?' . self::TABLE
        . ')/Sis';

    private const string WITH_CLAUSE = '/^\s*WITH\b/Si';

    // Group 1 is the CTE name, group 2 the placeholder of its body.
    private const string COMMON_TABLE_EXPRESSION = '/(?:\bWITH(?:\s+RECURSIVE)?|,)\s*(' . self::IDENTIFIER . ')'
        . '\s*(?:\(#\d+\)\s*)?AS\s+(?:NOT\s+)?(?:MATERIALIZED\s+)?\(#(\d+)\)/Si';

    // Bounds regex cost on huge statements (long IN lists, batch inserts); the table name sits near the start.
    private const int MAX_PARSED_SQL_LENGTH = 16_384;

    private const int CACHE_SIZE = 256;

    /** @var array<array-key, QueryLabels> least recently used first */
    private array $cache = [];

    /** @phpstan-impure Updates the cache. */
    #[Override]
    public function label(string $sql): QueryLabels
    {
        $sql = substr($sql, 0, self::MAX_PARSED_SQL_LENGTH);
        $key = hash('xxh128', $sql);

        $labels = $this->cache[$key] ?? null;
        if ($labels !== null) {
            unset($this->cache[$key]);
        } else {
            $labels = new QueryLabels($this->assembleType($sql), $this->assembleTableName($sql));
            if (\count($this->cache) >= self::CACHE_SIZE) {
                unset($this->cache[array_key_first($this->cache)]);
            }
        }

        return $this->cache[$key] = $labels;
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
            $sql,
        );

        return $sql === null ? null : $this->findTable($sql, []);
    }

    /**
     * Searches the top level only: parenthesised groups (function arguments, subqueries, column lists)
     * are folded into "(#n)" placeholders, so a FROM inside them is never mistaken for the query's own.
     * A derived table ("FROM (SELECT ...) t"), a parenthesised statement and a CTE body are searched recursively.
     *
     * @param array<string, string> $commonTables body of each visible CTE by lowercased name
     */
    private function findTable(string $sql, array $commonTables): ?string
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

        if ($topLevel === null) {
            return null;
        }

        // A statement wrapped in parentheses: each part of a UNION built by DBAL's QueryBuilder is.
        if (str_starts_with(ltrim($topLevel), '(#0)')) {
            return $this->findTableIn($groups, 0, $commonTables);
        }

        if (preg_match(self::WITH_CLAUSE, $topLevel) === 1) {
            preg_match_all(self::COMMON_TABLE_EXPRESSION, $topLevel, $definitions, \PREG_SET_ORDER);
            foreach ($definitions as [, $name, $body]) {
                $commonTables[strtolower($this->unquote($name))] = $groups[(int) $body] ?? '';
            }
        }

        if (preg_match(self::STATEMENT, $topLevel, $match) !== 1) {
            return null;
        }

        if (isset($match[1]) && $match[1] !== '') {
            return $this->findTableIn($groups, (int) $match[1], $commonTables);
        }

        foreach (\array_slice($match, 2) as $table) {
            if ($table === '') {
                continue;
            }

            $table = $this->unquote($table);
            $name = strtolower($table);
            if (!isset($commonTables[$name])) {
                return $table;
            }

            $body = $commonTables[$name];
            // A recursive CTE refers to itself; its own name is not resolved again.
            unset($commonTables[$name]);

            return $this->findTable($body, $commonTables) ?? $table;
        }

        return null;
    }

    /**
     * @param list<string> $groups
     * @param array<string, string> $commonTables
     */
    private function findTableIn(array $groups, int $index, array $commonTables): ?string
    {
        $inner = $groups[$index] ?? null;

        return $inner === null ? null : $this->findTable($inner, $commonTables);
    }

    private function unquote(string $identifier): string
    {
        return match ($identifier[0]) {
            '"' => str_replace('""', '"', substr($identifier, 1, -1)),
            '`' => str_replace('``', '`', substr($identifier, 1, -1)),
            '[' => substr($identifier, 1, -1),
            default => $identifier,
        };
    }
}
