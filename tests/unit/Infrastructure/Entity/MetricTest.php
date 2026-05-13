<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Test\Unit\Infrastructure\Entity;

use MaxShamaev\MetricsBundle\Infrastructure\Entity\Label;
use MaxShamaev\MetricsBundle\Infrastructure\Entity\Metric;
use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelTypeEnum;
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
