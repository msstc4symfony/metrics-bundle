<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Application\Metric;

use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Application\Metric\Label;
use Msstc4Symfony\MetricsBundle\Application\Metric\LabelType;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricRepository;
use PHPUnit\Framework\TestCase;

final class MetricRepositoryTest extends TestCase
{
    public function testFindAllOnEmptyListReturnsEmpty(): void
    {
        $repository = new MetricRepository([]);

        self::assertSame([], $repository->findAll());
    }

    public function testFindWrapsEnumAndPrependsBaseLabels(): void
    {
        $repository = new MetricRepository([]);

        $metric = $repository->find(BuiltInMetric::HTTP_REQUEST);

        self::assertSame(BuiltInMetric::HTTP_REQUEST, $metric->name);
        self::assertSame(BuiltInMetric::HTTP_REQUEST->getType(), $metric->type);
        self::assertSame(BuiltInMetric::HTTP_REQUEST->getDescription(), $metric->description);
        self::assertSame(BuiltInMetric::HTTP_REQUEST->getBatches(), $metric->batches);

        $expectedNames = array_merge(
            ['application', 'component'],
            array_map(
                static fn (Label $label): string => $label->name,
                BuiltInMetric::HTTP_REQUEST->getLabels(),
            ),
        );
        self::assertSame($expectedNames, $metric->labelNames);

        self::assertEquals(
            new Label('application', LabelType::STRING, 'Application name'),
            $metric->labels[0],
        );
    }

    public function testFindIsCachedAndReturnsSameInstance(): void
    {
        $repository = new MetricRepository([]);

        $first = $repository->find(BuiltInMetric::HTTP_REQUEST);
        $second = $repository->find(BuiltInMetric::HTTP_REQUEST);

        self::assertSame($first, $second);
    }

    public function testFindAllReturnsMetricsForEachEnumCase(): void
    {
        $repository = new MetricRepository([
            BuiltInMetric::HTTP_REQUEST,
            BuiltInMetric::CONSOLE_COMMAND_START,
        ]);

        $metrics = $repository->findAll();

        self::assertCount(2, $metrics);
        self::assertSame(BuiltInMetric::HTTP_REQUEST, $metrics[0]->name);
        self::assertSame(BuiltInMetric::CONSOLE_COMMAND_START, $metrics[1]->name);
    }
}
