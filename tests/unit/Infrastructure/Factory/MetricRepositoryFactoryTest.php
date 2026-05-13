<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Test\Unit\Infrastructure\Factory;

use MaxShamaev\MetricsBundle\Infrastructure\Entity\Label;
use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnumInterface;
use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelTypeEnum;
use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricTypeEnum;
use MaxShamaev\MetricsBundle\Infrastructure\Factory\MetricRepositoryFactory;
use MaxShamaev\MetricsBundle\Infrastructure\Repository\MetricRepository;
use PHPUnit\Framework\TestCase;

final class MetricRepositoryFactoryTest extends TestCase
{
    public function testCreateWithEmptyListReturnsEmptyRepository(): void
    {
        $factory = new MetricRepositoryFactory([]);

        self::assertEquals(new MetricRepository([]), $factory->create());
    }

    public function testCreateAcceptsStringBackedMetricEnum(): void
    {
        $factory = new MetricRepositoryFactory([MetricLabelEnum::class]);

        $repository = $factory->create();

        self::assertCount(\count(MetricLabelEnum::cases()), $repository->findAll());
    }

    public function testCreateSkipsClassNotImplementingMetricLabelEnumInterface(): void
    {
        $factory = new MetricRepositoryFactory([UnrelatedClassFixture::class]);

        self::assertEquals(new MetricRepository([]), $factory->create());
    }

    public function testCreateSkipsIntBackedEnumEvenIfItImplementsInterface(): void
    {
        $factory = new MetricRepositoryFactory([IntBackedMetricFixture::class]);

        self::assertEquals(new MetricRepository([]), $factory->create());
    }

    public function testCreateMergesCasesFromMultipleEnums(): void
    {
        $factory = new MetricRepositoryFactory([MetricLabelEnum::class, ExtraMetricFixture::class]);

        $repository = $factory->create();

        self::assertCount(
            \count(MetricLabelEnum::cases()) + \count(ExtraMetricFixture::cases()),
            $repository->findAll(),
        );
    }
}

final class UnrelatedClassFixture
{
}

enum IntBackedMetricFixture: int implements MetricLabelEnumInterface
{
    case TEST = 1;

    public function getType(): MetricTypeEnum
    {
        return MetricTypeEnum::COUNTER;
    }

    public function getDescription(): string
    {
        return 'test';
    }

    public function getLabels(): array
    {
        return [];
    }

    public function getBatches(): array
    {
        return [];
    }
}

enum ExtraMetricFixture: string implements MetricLabelEnumInterface
{
    case CUSTOM_ONE = 'custom_one';
    case CUSTOM_TWO = 'custom_two';

    public function getType(): MetricTypeEnum
    {
        return MetricTypeEnum::COUNTER;
    }

    public function getDescription(): string
    {
        return 'custom metric';
    }

    public function getLabels(): array
    {
        return [new Label('tag', MetricLabelTypeEnum::STRING)];
    }

    public function getBatches(): array
    {
        return [];
    }
}
