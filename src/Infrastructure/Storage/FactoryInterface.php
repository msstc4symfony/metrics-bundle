<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Storage;

use Prometheus\Storage\Adapter;

interface FactoryInterface
{
    public function create(string $dsn): Adapter;
}
