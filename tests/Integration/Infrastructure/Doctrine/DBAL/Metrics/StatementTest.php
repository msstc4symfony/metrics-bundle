<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Infrastructure\Doctrine\DBAL\Metrics;

use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Msstc4Symfony\MetricsBundle\Application\Collector\DoctrineConnectionCollector;
use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricRepository;
use Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics\QueryLabeller;
use Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics\QueryMeter;
use Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics\Statement;
use Msstc4Symfony\MetricsBundle\Infrastructure\Prometheus\PrometheusMetricRecorder;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;
use Override;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;

final class StatementTest extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        if (!interface_exists(StatementInterface::class)) {
            self::markTestSkipped('doctrine/dbal not installed');
        }
    }

    public function testExecuteForwardsToInnerStatementAndRecordsTheQuery(): void
    {
        $result = self::createStub(Result::class);
        $inner = $this->createMock(StatementInterface::class);
        $inner->expects(self::once())->method('execute')->willReturn($result);

        $registry = new CollectorRegistry(new InMemory());
        $collector = new DoctrineConnectionCollector(new PrometheusMetricRecorder($registry, new MetricRepository([]), 'app', 'cmp'));

        self::assertSame($result, (new Statement($inner, new QueryMeter($collector, 'default', new QueryLabeller()), 'SELECT * FROM users'))->execute());

        self::assertSame([['app', 'cmp', 'default', 'select', 'users']], RegistrySamples::labels($registry, BuiltInMetric::DOCTRINE_QUERY_EXECUTE));
    }
}
