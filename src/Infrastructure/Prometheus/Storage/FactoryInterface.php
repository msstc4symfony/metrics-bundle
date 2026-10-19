<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Prometheus\Storage;

use Prometheus\Storage\Adapter;

/**
 * @internal
 */
interface FactoryInterface
{
    public function create(string $dsn): Adapter;
}
