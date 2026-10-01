<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Infrastructure\Doctrine\DBAL\Metrics;

use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\DoctrineConnectionCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics\QueryMeter;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepository;
use PHPUnit\Framework\Assert;
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

        $meter->measure($sql, static fn (): null => null);

        [[, , , $type]] = $this->executed($registry);
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
    }

    #[DataProvider('tableNameProvider')]
    public function testRecordsTableName(string $sql, string $expected): void
    {
        [$registry, $meter] = $this->buildMeter();

        $meter->measure($sql, static fn (): null => null);

        [[, , , , $table]] = $this->executed($registry);
        self::assertSame($expected, $table);
    }

    public function testReturnsTheQueryResultAndRecordsCounterAndHistogram(): void
    {
        [$registry, $meter] = $this->buildMeter();

        self::assertSame(42, $meter->measure('SELECT * FROM users', static fn (): int => 42));

        self::assertSame([['app', 'cmp', 'default', 'select', 'users']], $this->executed($registry));

        $observed = 0;
        foreach ($registry->getMetricFamilySamples() as $family) {
            if ($family->getName() === 'symfony_' . MetricLabelEnum::DOCTRINE_QUERY_DURATION_HISTOGRAM_SECONDS->value) {
                foreach ($family->getSamples() as $sample) {
                    if (str_ends_with($sample->getName(), '_count')) {
                        $observed += (int) $sample->getValue();
                    }
                }
            }
        }

        self::assertSame(1, $observed);
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

        self::assertSame([], $this->executed($registry));
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

    /**
     * @return list<list<string>>
     */
    private function executed(CollectorRegistry $registry): array
    {
        $executed = [];
        foreach ($registry->getMetricFamilySamples() as $family) {
            if ($family->getName() === 'symfony_' . MetricLabelEnum::DOCTRINE_QUERY_EXECUTE->value) {
                foreach ($family->getSamples() as $sample) {
                    $labels = [];
                    foreach ($sample->getLabelValues() as $value) {
                        Assert::assertIsString($value);
                        $labels[] = $value;
                    }

                    $executed[] = $labels;
                }
            }
        }

        return $executed;
    }
}
