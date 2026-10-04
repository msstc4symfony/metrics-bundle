<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Infrastructure\Entity;

use Msstc4Symfony\MetricsBundle\Infrastructure\Entity\Label;
use Msstc4Symfony\MetricsBundle\Infrastructure\Entity\Metric;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelTypeEnum;
use PHPUnit\Framework\TestCase;

final class MetricTest extends TestCase
{
    public function testLabelNamesPrecomputedInConstructor(): void
    {
        $metric = new Metric(
            MetricLabelEnum::HTTP_REQUEST,
            MetricLabelEnum::HTTP_REQUEST->getType(),
            'desc',
            [
                new Label('a', MetricLabelTypeEnum::STRING),
                new Label('b', MetricLabelTypeEnum::INTEGER),
            ],
            [],
        );

        self::assertSame(['a', 'b'], $metric->labelNames);
    }

    public function testEmptyLabelsProduceEmptyNames(): void
    {
        $metric = new Metric(
            MetricLabelEnum::HTTP_REQUEST,
            MetricLabelEnum::HTTP_REQUEST->getType(),
            'desc',
            [],
            [],
        );

        self::assertSame([], $metric->labelNames);
    }
}
