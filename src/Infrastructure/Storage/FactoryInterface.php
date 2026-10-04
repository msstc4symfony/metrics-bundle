<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Storage;

use Prometheus\Storage\Adapter;

interface FactoryInterface
{
    public function create(string $dsn): Adapter;
}
