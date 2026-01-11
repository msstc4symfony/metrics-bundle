<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Test\Unit\Infrastructure\Repository;

use MaxShamaev\MetricsBundle\Infrastructure\Entity\Label;
use MaxShamaev\MetricsBundle\Infrastructure\Entity\Metric;
use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelTypeEnum;
use MaxShamaev\MetricsBundle\Infrastructure\Repository\MetricRepository;
use PHPUnit\Framework\TestCase;

final class MetricRepositoryTest extends TestCase
{
    public function testFindAll(): void
    {
        $repository = new MetricRepository([]);
        self::assertEquals([], $repository->findAll());
    }

    public function testFind(): void
    {
        $repository = new MetricRepository([]);
        self::assertEquals(
            new Metric(
                MetricLabelEnum::HTTP_REQUEST,
                MetricLabelEnum::HTTP_REQUEST->getType(),
                MetricLabelEnum::HTTP_REQUEST->getDescription(),
                array_merge(
                    [
                        new Label('application', MetricLabelTypeEnum::STRING, 'Application name'),
                        new Label('component', MetricLabelTypeEnum::STRING, 'Application component name'),
                        new Label('container', MetricLabelTypeEnum::STRING, 'Container / pod ID'),
                    ],
                    MetricLabelEnum::HTTP_REQUEST->getLabels(),
                    MetricLabelEnum::HTTP_REQUEST->getBatches(),
                ),
                MetricLabelEnum::HTTP_REQUEST->getBatches(),
            ),
            $repository->find(MetricLabelEnum::HTTP_REQUEST),
        );
    }
}
