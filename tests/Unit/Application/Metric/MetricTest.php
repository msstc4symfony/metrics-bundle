<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Application\Metric;

use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Application\Metric\Label;
use Msstc4Symfony\MetricsBundle\Application\Metric\LabelType;
use Msstc4Symfony\MetricsBundle\Application\Metric\Metric;
use PHPUnit\Framework\TestCase;

final class MetricTest extends TestCase
{
    public function testLabelNamesPrecomputedInConstructor(): void
    {
        $metric = new Metric(
            BuiltInMetric::HTTP_REQUEST,
            BuiltInMetric::HTTP_REQUEST->getType(),
            'desc',
            [
                new Label('a', LabelType::STRING),
                new Label('b', LabelType::INTEGER),
            ],
            [],
        );

        self::assertSame(['a', 'b'], $metric->labelNames);
    }

    public function testEmptyLabelsProduceEmptyNames(): void
    {
        $metric = new Metric(
            BuiltInMetric::HTTP_REQUEST,
            BuiltInMetric::HTTP_REQUEST->getType(),
            'desc',
            [],
            [],
        );

        self::assertSame([], $metric->labelNames);
    }
}
