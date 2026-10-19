<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Application\Metric;

use LogicException;
use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Application\Metric\Label;
use Msstc4Symfony\MetricsBundle\Application\Metric\LabelType;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricDefinitionInterface;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricRepository;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricRepositoryFactory;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricType;
use Override;
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
        $factory = new MetricRepositoryFactory([BuiltInMetric::class]);

        $repository = $factory->create();

        self::assertCount(\count(BuiltInMetric::cases()), $repository->findAll());
    }

    public function testCreateSkipsClassNotImplementingMetricDefinitionInterface(): void
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
        $factory = new MetricRepositoryFactory([BuiltInMetric::class, ExtraMetricFixture::class]);

        $repository = $factory->create();

        self::assertCount(
            \count(BuiltInMetric::cases()) + \count(ExtraMetricFixture::cases()),
            $repository->findAll(),
        );
    }

    public function testADuplicateMetricNameIsRejectedWithBothDeclarations(): void
    {
        $factory = new MetricRepositoryFactory([BuiltInMetric::class, DuplicateMetricFixture::class]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(\sprintf(
            'Metric "exception" is declared by both %s::EXCEPTION and %s::REDEFINED.',
            BuiltInMetric::class,
            DuplicateMetricFixture::class,
        ));

        $factory->create();
    }

    public function testAnEnumListedTwiceIsReadOnce(): void
    {
        $factory = new MetricRepositoryFactory([BuiltInMetric::class, ExtraMetricFixture::class, BuiltInMetric::class]);

        self::assertCount(
            \count(BuiltInMetric::cases()) + \count(ExtraMetricFixture::cases()),
            $factory->create()->findAll(),
        );
    }
}

enum DuplicateMetricFixture: string implements MetricDefinitionInterface
{
    case REDEFINED = 'exception';
    case NEW_ONE = 'duplicate_fixture_new_one';

    #[Override]
    public function getType(): MetricType
    {
        return MetricType::HISTOGRAM;
    }

    #[Override]
    public function getDescription(): string
    {
        return 'redefined';
    }

    #[Override]
    public function getLabels(): array
    {
        return [];
    }

    #[Override]
    public function getBatches(): array
    {
        return [];
    }
}

final class UnrelatedClassFixture
{
}

enum IntBackedMetricFixture: int implements MetricDefinitionInterface
{
    case TEST = 1;

    #[Override]
    public function getType(): MetricType
    {
        return MetricType::COUNTER;
    }

    #[Override]
    public function getDescription(): string
    {
        return 'test';
    }

    #[Override]
    public function getLabels(): array
    {
        return [];
    }

    #[Override]
    public function getBatches(): array
    {
        return [];
    }
}

enum ExtraMetricFixture: string implements MetricDefinitionInterface
{
    case CUSTOM_ONE = 'custom_one';
    case CUSTOM_TWO = 'custom_two';

    #[Override]
    public function getType(): MetricType
    {
        return MetricType::COUNTER;
    }

    #[Override]
    public function getDescription(): string
    {
        return 'custom metric';
    }

    #[Override]
    public function getLabels(): array
    {
        return [new Label('tag', LabelType::STRING)];
    }

    #[Override]
    public function getBatches(): array
    {
        return [];
    }
}
