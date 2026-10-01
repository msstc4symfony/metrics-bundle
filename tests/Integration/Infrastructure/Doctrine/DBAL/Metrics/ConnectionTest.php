<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Infrastructure\Doctrine\DBAL\Metrics;

use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\DoctrineConnectionCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics\Connection;
use Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics\Statement;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepository;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;

final class ConnectionTest extends TestCase
{
    private CollectorRegistry $registry;

    private DoctrineConnectionCollector $collector;

    protected function setUp(): void
    {
        if (!interface_exists(ConnectionInterface::class)) {
            self::markTestSkipped('doctrine/dbal not installed');
        }

        $this->registry = new CollectorRegistry(new InMemory());
        $this->collector = new DoctrineConnectionCollector($this->registry, new MetricRepository([]), 'app', 'cmp');
    }

    public function testPrepareWrapsTheStatementWithoutRecordingYet(): void
    {
        $inner = $this->createMock(ConnectionInterface::class);
        $inner->expects(self::once())->method('prepare')->with('SELECT * FROM users')
            ->willReturn(self::createStub(StatementInterface::class))
        ;

        $statement = new Connection($inner, $this->collector, 'default')->prepare('SELECT * FROM users');

        self::assertInstanceOf(Statement::class, $statement);
        self::assertSame([], DbalMetrics::executed($this->registry));
    }

    public function testQueryWithoutParametersIsRecorded(): void
    {
        $result = self::createStub(Result::class);
        $inner = $this->createMock(ConnectionInterface::class);
        $inner->expects(self::once())->method('query')->with('SELECT * FROM users')->willReturn($result);

        self::assertSame($result, new Connection($inner, $this->collector, 'default')->query('SELECT * FROM users'));
        self::assertSame([['app', 'cmp', 'default', 'select', 'users']], DbalMetrics::executed($this->registry));
    }

    public function testExecWithoutParametersIsRecorded(): void
    {
        $inner = $this->createMock(ConnectionInterface::class);
        $inner->expects(self::once())->method('exec')->with('DELETE FROM users')->willReturn(3);

        self::assertSame(3, new Connection($inner, $this->collector, 'default')->exec('DELETE FROM users'));
        self::assertSame([['app', 'cmp', 'default', 'delete', 'users']], DbalMetrics::executed($this->registry));
    }
}
