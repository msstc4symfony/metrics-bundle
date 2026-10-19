<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Application\Collector;

use Msstc4Symfony\MetricsBundle\Application\Collector\DoctrineConnectionCollector;
use Msstc4Symfony\MetricsBundle\Application\Collector\DoctrineQueryType;
use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DoctrineConnectionCollector::class)]
final class DoctrineConnectionCollectorTest extends RecorderTestCase
{
    public function testIncQueryExecuteLabelsConnectionTypeAndTable(): void
    {
        $this->build()->incQueryExecute('default', DoctrineQueryType::SELECT, 'users');

        self::assertSame([['increment', BuiltInMetric::DOCTRINE_QUERY_EXECUTE, null, ['default', 'select', 'users']]], $this->recorder->calls);
    }

    public function testIncQueryExecuteNullTableFallsBackToUnknown(): void
    {
        $this->build()->incQueryExecute('default', DoctrineQueryType::OTHER, null);

        self::assertSame([['increment', BuiltInMetric::DOCTRINE_QUERY_EXECUTE, null, ['default', 'other', 'unknown']]], $this->recorder->calls);
    }

    public function testSetQueryExecuteDurationObserved(): void
    {
        $this->build()->setQueryExecuteDuration('default', DoctrineQueryType::INSERT, 'orders', 0.05);

        self::assertSame([['observe', BuiltInMetric::DOCTRINE_QUERY_DURATION_HISTOGRAM_SECONDS, 0.05, ['default', 'insert', 'orders']]], $this->recorder->calls);
    }

    private function build(): DoctrineConnectionCollector
    {
        return new DoctrineConnectionCollector($this->recorder);
    }
}
