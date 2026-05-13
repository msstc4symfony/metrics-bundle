<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Test\Unit\Infrastructure\Collector;

use MaxShamaev\MetricsBundle\Infrastructure\Collector\DoctrineConnectionCollector;
use MaxShamaev\MetricsBundle\Infrastructure\Enum\DoctrineQueryTypeEnum;
use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use MaxShamaev\MetricsBundle\Infrastructure\Repository\MetricRepository;

final class DoctrineConnectionCollectorTest extends CollectorTestCase
{
    public function testIncQueryExecuteLabelsConnectionTypeAndTable(): void
    {
        $this->build()->incQueryExecute('default', DoctrineQueryTypeEnum::SELECT, 'users');

        self::assertSame(
            [['app', 'cmp', 'default', 'select', 'users']],
            $this->labelValuesFor('symfony_' . MetricLabelEnum::DOCTRINE_QUERY_EXECUTE->value),
        );
    }

    public function testIncQueryExecuteNullTableFallsBackToUnknown(): void
    {
        $this->build()->incQueryExecute('default', DoctrineQueryTypeEnum::OTHER, null);

        self::assertSame(
            [['app', 'cmp', 'default', 'other', 'unknown']],
            $this->labelValuesFor('symfony_' . MetricLabelEnum::DOCTRINE_QUERY_EXECUTE->value),
        );
    }

    public function testSetQueryExecuteDurationObserved(): void
    {
        $this->build()->setQueryExecuteDuration('default', DoctrineQueryTypeEnum::INSERT, 'orders', 0.05);

        self::assertTrue($this->familyExists('symfony_' . MetricLabelEnum::DOCTRINE_QUERY_DURATION_HISTOGRAM_SECONDS->value));
    }

    private function build(): DoctrineConnectionCollector
    {
        return new DoctrineConnectionCollector($this->registry, new MetricRepository([]), 'app', 'cmp');
    }
}
