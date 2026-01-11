<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Repository;

use MaxShamaev\MetricsBundle\Infrastructure\Entity\Metric;
use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnumInterface;

interface MetricRepositoryInterface
{
    /**
     * @return Metric[]
     */
    public function findAll(): array;

    public function find(MetricLabelEnumInterface $label): Metric;
}
