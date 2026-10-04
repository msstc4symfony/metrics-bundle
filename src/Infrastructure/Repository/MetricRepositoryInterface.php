<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Repository;

use Msstc4Symfony\MetricsBundle\Infrastructure\Entity\Metric;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnumInterface;

interface MetricRepositoryInterface
{
    /**
     * @return Metric[]
     */
    public function findAll(): array;

    public function find(MetricLabelEnumInterface $label): Metric;
}
