<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Infrastructure\Collector;

use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\DoctrineConnectionCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\DoctrineQueryTypeEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepository;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;

final class DoctrineConnectionCollectorTest extends CollectorTestCase
{
    public function testIncQueryExecuteLabelsConnectionTypeAndTable(): void
    {
        $this->build()->incQueryExecute('default', DoctrineQueryTypeEnum::SELECT, 'users');

        self::assertSame(
            [['app', 'cmp', 'default', 'select', 'users']],
            RegistrySamples::labels($this->registry, MetricLabelEnum::DOCTRINE_QUERY_EXECUTE),
        );
    }

    public function testIncQueryExecuteNullTableFallsBackToUnknown(): void
    {
        $this->build()->incQueryExecute('default', DoctrineQueryTypeEnum::OTHER, null);

        self::assertSame(
            [['app', 'cmp', 'default', 'other', 'unknown']],
            RegistrySamples::labels($this->registry, MetricLabelEnum::DOCTRINE_QUERY_EXECUTE),
        );
    }

    public function testSetQueryExecuteDurationObserved(): void
    {
        $this->build()->setQueryExecuteDuration('default', DoctrineQueryTypeEnum::INSERT, 'orders', 0.05);

        self::assertTrue(RegistrySamples::exists($this->registry, MetricLabelEnum::DOCTRINE_QUERY_DURATION_HISTOGRAM_SECONDS));
    }

    private function build(): DoctrineConnectionCollector
    {
        return new DoctrineConnectionCollector($this->registry, new MetricRepository([]), 'app', 'cmp');
    }
}
