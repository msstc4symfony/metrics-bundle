<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Application\Metric;

interface MetricRepositoryInterface
{
    /**
     * @return list<Metric>
     */
    public function findAll(): array;

    public function find(MetricDefinitionInterface $label): Metric;
}
