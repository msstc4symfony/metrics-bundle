<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Infrastructure\Doctrine\DBAL\Metrics;

use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\DoctrineConnectionCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics\Statement;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\DoctrineQueryTypeEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;
use ReflectionMethod;

final class StatementTest extends TestCase
{
    protected function setUp(): void
    {
        if (!interface_exists(StatementInterface::class)) {
            self::markTestSkipped('doctrine/dbal not installed');
        }
    }

    /**
     * @return iterable<string, array{string, DoctrineQueryTypeEnum}>
     */
    public static function typeProvider(): iterable
    {
        yield 'SELECT uppercase' => ['SELECT * FROM users', DoctrineQueryTypeEnum::SELECT];
        yield 'select lowercase' => ['select * from users', DoctrineQueryTypeEnum::SELECT];
        yield 'INSERT' => ['INSERT INTO users (name) VALUES (1)', DoctrineQueryTypeEnum::INSERT];
        yield 'UPDATE' => ['UPDATE users SET name = ?', DoctrineQueryTypeEnum::UPDATE];
        yield 'DELETE' => ['DELETE FROM users WHERE id = 1', DoctrineQueryTypeEnum::DELETE];
        yield 'leading whitespace' => ["  \n  SELECT 1", DoctrineQueryTypeEnum::SELECT];
        yield 'unknown becomes OTHER' => ['CREATE TABLE foo (id INT)', DoctrineQueryTypeEnum::OTHER];
        yield 'empty becomes OTHER' => ['', DoctrineQueryTypeEnum::OTHER];
    }

    #[DataProvider('typeProvider')]
    public function testAssembleType(string $sql, DoctrineQueryTypeEnum $expected): void
    {
        $method = new ReflectionMethod(Statement::class, 'assembleType');

        self::assertSame($expected, $method->invoke($this->buildStatement($sql), $sql));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function tableNameProvider(): iterable
    {
        yield 'SELECT FROM' => ['SELECT * FROM users WHERE id = 1', 'users'];
        yield 'INSERT INTO' => ['INSERT INTO orders (id) VALUES (1)', 'orders'];
        yield 'DELETE FROM' => ['DELETE FROM products WHERE id = 1', 'products'];
        yield 'UPDATE' => ['UPDATE accounts SET balance = 0 WHERE id = 1', 'accounts'];
        yield 'underscores' => ['SELECT * FROM user_logs WHERE id = 1', 'user_logs'];
        yield 'no table' => ['CREATE TABLE foo (id INT)', null];
    }

    #[DataProvider('tableNameProvider')]
    public function testAssembleTableName(string $sql, ?string $expected): void
    {
        $method = new ReflectionMethod(Statement::class, 'assembleTableName');

        self::assertSame($expected, $method->invoke($this->buildStatement($sql), $sql));
    }

    private function buildStatement(string $sql): Statement
    {
        $registry = new CollectorRegistry(new InMemory());
        $collector = new DoctrineConnectionCollector($registry, new MetricRepository([]), 'app', 'cmp');

        return new Statement(
            self::createStub(StatementInterface::class),
            $collector,
            'default',
            $sql,
        );
    }
}
