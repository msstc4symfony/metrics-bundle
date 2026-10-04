<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Infrastructure\Collector;

use Override;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;

abstract class CollectorTestCase extends TestCase
{
    protected CollectorRegistry $registry;

    #[Override]
    protected function setUp(): void
    {
        $this->registry = new CollectorRegistry(new InMemory());
    }
}
