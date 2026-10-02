<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Infrastructure\Doctrine\DBAL\Metrics;

use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\DoctrineConnectionCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics\QueryMeter;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepository;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;
use RuntimeException;

final class QueryMeterTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function typeProvider(): iterable
    {
        yield 'SELECT uppercase' => ['SELECT * FROM users', 'select'];
        yield 'select lowercase' => ['select * from users', 'select'];
        yield 'INSERT' => ['INSERT INTO users (name) VALUES (1)', 'insert'];
        yield 'UPDATE' => ['UPDATE users SET name = ?', 'update'];
        yield 'DELETE' => ['DELETE FROM users WHERE id = 1', 'delete'];
        yield 'leading whitespace' => ["  \n  SELECT 1", 'select'];
        yield 'unknown becomes OTHER' => ['CREATE TABLE foo (id INT)', 'other'];
        yield 'empty becomes OTHER' => ['', 'other'];
    }

    #[DataProvider('typeProvider')]
    public function testRecordsQueryType(string $sql, string $expected): void
    {
        [$registry, $meter] = $this->buildMeter();

        $meter->measure($sql, static fn (): int => 0);

        [[, , , $type]] = RegistrySamples::labels($registry, MetricLabelEnum::DOCTRINE_QUERY_EXECUTE);
        self::assertSame($expected, $type);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function tableNameProvider(): iterable
    {
        yield 'SELECT FROM' => ['SELECT * FROM users WHERE id = 1', 'users'];
        yield 'INSERT INTO' => ['INSERT INTO orders (id) VALUES (1)', 'orders'];
        yield 'DELETE FROM' => ['DELETE FROM products WHERE id = 1', 'products'];
        yield 'UPDATE' => ['UPDATE accounts SET balance = 0 WHERE id = 1', 'accounts'];
        yield 'underscores' => ['SELECT * FROM user_logs WHERE id = 1', 'user_logs'];
        yield 'SELECT without WHERE' => ['SELECT * FROM users', 'users'];
        yield 'INSERT without column list' => ['INSERT INTO orders VALUES (1)', 'orders'];
        yield 'UPDATE at end of line' => ["UPDATE accounts\nSET balance = 0", 'accounts'];
        yield 'schema-qualified SELECT' => ['SELECT * FROM public.users WHERE id = 1', 'users'];
        yield 'schema-qualified UPDATE' => ['UPDATE app.orders SET x = 1', 'orders'];
        yield 'no table' => ['CREATE TABLE foo (id INT)', 'unknown'];
        yield 'subquery in WHERE labels the outer table' => [
            'SELECT * FROM users WHERE id IN (SELECT user_id FROM orders)',
            'users',
        ];
        yield 'JOIN labels the first FROM table' => [
            "SELECT u.id FROM users u\nJOIN orders o ON o.user_id = u.id\nWHERE o.id IN (SELECT id FROM refunds)",
            'users',
        ];
        yield 'FROM beyond the parsed prefix is not searched' => [
            'SELECT ' . str_repeat('a, ', 6_000) . 'b FROM users',
            'unknown',
        ];
        yield 'long IN list after the table' => [
            'SELECT * FROM users WHERE id IN (' . implode(', ', range(1, 200_000)) . ')',
            'users',
        ];
        yield 'FROM inside a function call' => ['SELECT EXTRACT(EPOCH FROM created_at) FROM events', 'events'];
        yield 'scalar subquery in the select list labels the outer table' => [
            'SELECT (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id) AS n FROM users u',
            'users',
        ];
        yield 'FROM in a line comment' => ["SELECT id -- copied from partitioned tables\nFROM users", 'users'];
        yield 'FROM in a block comment' => ['SELECT /* from audit */ id FROM users', 'users'];
        yield 'FROM in a string literal' => ["SELECT 'from audit' AS source, id FROM users", 'users'];
        yield 'escaped quote in a string literal' => ["SELECT 'it''s from audit' AS s FROM users", 'users'];
        yield 'derived table labels its inner table' => ['SELECT n FROM (SELECT COUNT(*) AS n FROM orders) t', 'orders'];
        yield 'double-quoted identifier' => ['SELECT * FROM "public"."user_logs" WHERE id = 1', 'user_logs'];
        yield 'backtick identifier' => ['SELECT * FROM `orders`', 'orders'];
        yield 'quoted INSERT target' => ['INSERT INTO "orders" (id) VALUES (1)', 'orders'];
        yield 'Postgres column introspection by DBAL' => [self::POSTGRES_COLUMNS_SQL, 'pg_attribute'];
    }

    // Shape of PostgreSQLSchemaManager::selectTableColumns() (DBAL 4): a subquery in the select list,
    // a scalar subquery in a JOIN condition and "from" in a trailing comment.
    private const string POSTGRES_COLUMNS_SQL = <<<'SQL'
        SELECT quote_ident(n.nspname) AS schema_name,
               quote_ident(c.relname) AS table_name,
               format_type(a.atttypid, a.atttypmod) AS complete_type,
               (SELECT pg_get_expr(adbin, adrelid)
                FROM pg_attrdef
                WHERE c.oid = pg_attrdef.adrelid AND pg_attrdef.adnum = a.attnum) AS "default"
        FROM pg_attribute a
                 JOIN pg_class c ON c.oid = a.attrelid
                 LEFT JOIN pg_depend dep
                           ON dep.objid = c.oid
                               AND dep.classid = (SELECT oid FROM pg_class WHERE relname = 'pg_class')
        WHERE c.relname = ?
          -- 'r' for regular tables - 'p' for partitioned tables
          AND c.relkind IN ('r', 'p')
          -- exclude partitions (tables that inherit from partitioned tables)
          AND dep.refobjid IS NULL
        SQL;

    #[DataProvider('tableNameProvider')]
    public function testRecordsTableName(string $sql, string $expected): void
    {
        [$registry, $meter] = $this->buildMeter();

        $meter->measure($sql, static fn (): int => 0);

        [[, , , , $table]] = RegistrySamples::labels($registry, MetricLabelEnum::DOCTRINE_QUERY_EXECUTE);
        self::assertSame($expected, $table);
    }

    public function testReturnsTheQueryResultAndRecordsCounterAndHistogram(): void
    {
        [$registry, $meter] = $this->buildMeter();

        self::assertSame(42, $meter->measure('SELECT * FROM users', static fn (): int => 42));

        self::assertSame([['app', 'cmp', 'default', 'select', 'users']], RegistrySamples::labels($registry, MetricLabelEnum::DOCTRINE_QUERY_EXECUTE));

        self::assertSame(
            [[['app', 'cmp', 'default', 'select', 'users'], '1']],
            RegistrySamples::samples($registry, MetricLabelEnum::DOCTRINE_QUERY_DURATION_HISTOGRAM_SECONDS, '_count'),
        );
    }

    public function testFailedQueryIsNotRecorded(): void
    {
        [$registry, $meter] = $this->buildMeter();

        $caught = null;
        try {
            $meter->measure('SELECT * FROM users', static fn (): never => throw new RuntimeException('boom'));
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(RuntimeException::class, $caught);

        self::assertSame([], RegistrySamples::labels($registry, MetricLabelEnum::DOCTRINE_QUERY_EXECUTE));
    }

    /**
     * @return array{CollectorRegistry, QueryMeter}
     */
    private function buildMeter(): array
    {
        $registry = new CollectorRegistry(new InMemory());
        $collector = new DoctrineConnectionCollector($registry, new MetricRepository([]), 'app', 'cmp');

        return [$registry, new QueryMeter($collector, 'default')];
    }
}
