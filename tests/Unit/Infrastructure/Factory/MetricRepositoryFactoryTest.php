<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Infrastructure\Factory;

use LogicException;
use Msstc4Symfony\MetricsBundle\Infrastructure\Entity\Label;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnumInterface;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelTypeEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricTypeEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Factory\MetricRepositoryFactory;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepository;
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

    public function testADuplicateMetricNameIsRejectedWithBothDeclarations(): void
    {
        $factory = new MetricRepositoryFactory([MetricLabelEnum::class, DuplicateMetricFixture::class]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(\sprintf(
            'Metric "exception" is declared by both %s::EXCEPTION and %s::REDEFINED.',
            MetricLabelEnum::class,
            DuplicateMetricFixture::class,
        ));

        $factory->create();
    }

    public function testAnEnumListedTwiceIsReadOnce(): void
    {
        $factory = new MetricRepositoryFactory([MetricLabelEnum::class, ExtraMetricFixture::class, MetricLabelEnum::class]);

        self::assertCount(
            \count(MetricLabelEnum::cases()) + \count(ExtraMetricFixture::cases()),
            $factory->create()->findAll(),
        );
    }
}

enum DuplicateMetricFixture: string implements MetricLabelEnumInterface
{
    case REDEFINED = 'exception';
    case NEW_ONE = 'duplicate_fixture_new_one';

    public function getType(): MetricTypeEnum
    {
        return MetricTypeEnum::HISTOGRAM;
    }

    public function getDescription(): string
    {
        return 'redefined';
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
