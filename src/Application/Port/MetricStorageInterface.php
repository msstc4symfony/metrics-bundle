<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Application\Port;

interface MetricStorageInterface
{
    /**
     * @throws MetricStorageException
     */
    public function export(): MetricExport;

    /**
     * @throws MetricStorageException
     */
    public function clear(): void;
}
