<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Test\Unit\Infrastructure\Repository;

use MaxShamaev\MetricsBundle\Infrastructure\Entity\Label;
use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelTypeEnum;
use MaxShamaev\MetricsBundle\Infrastructure\Repository\MetricRepository;
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

        $metric = $repository->find(MetricLabelEnum::HTTP_REQUEST);

        self::assertSame(MetricLabelEnum::HTTP_REQUEST, $metric->name);
        self::assertSame(MetricLabelEnum::HTTP_REQUEST->getType(), $metric->type);
        self::assertSame(MetricLabelEnum::HTTP_REQUEST->getDescription(), $metric->description);
        self::assertSame(MetricLabelEnum::HTTP_REQUEST->getBatches(), $metric->batches);

        $expectedNames = array_merge(
            ['application', 'component'],
            array_map(
                static fn (Label $label): string => $label->name,
                MetricLabelEnum::HTTP_REQUEST->getLabels(),
            ),
        );
        self::assertSame($expectedNames, $metric->labelNames);

        self::assertEquals(
            new Label('application', MetricLabelTypeEnum::STRING, 'Application name'),
            $metric->labels[0],
        );
    }

    public function testFindIsCachedAndReturnsSameInstance(): void
    {
        $repository = new MetricRepository([]);

        $first = $repository->find(MetricLabelEnum::HTTP_REQUEST);
        $second = $repository->find(MetricLabelEnum::HTTP_REQUEST);

        self::assertSame($first, $second);
    }

    public function testFindAllReturnsMetricsForEachEnumCase(): void
    {
        $repository = new MetricRepository([
            MetricLabelEnum::HTTP_REQUEST,
            MetricLabelEnum::CONSOLE_COMMAND_START,
        ]);

        $metrics = $repository->findAll();

        self::assertCount(2, $metrics);
        self::assertSame(MetricLabelEnum::HTTP_REQUEST, $metrics[0]->name);
        self::assertSame(MetricLabelEnum::CONSOLE_COMMAND_START, $metrics[1]->name);
    }
}
