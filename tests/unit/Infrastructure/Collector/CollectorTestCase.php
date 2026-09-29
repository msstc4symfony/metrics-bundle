<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Infrastructure\Collector;

use Override;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Sample;
use Prometheus\Storage\InMemory;

abstract class CollectorTestCase extends TestCase
{
    protected CollectorRegistry $registry;

    #[Override]
    protected function setUp(): void
    {
        $this->registry = new CollectorRegistry(new InMemory());
    }

    /**
     * @return array<array<int, string>>
     */
    protected function labelValuesFor(string $name): array
    {
        foreach ($this->registry->getMetricFamilySamples() as $family) {
            if ($family->getName() !== $name) {
                continue;
            }

            return array_map(
                static fn (Sample $sample): array => $sample->getLabelValues(),
                $family->getSamples(),
            );
        }

        return [];
    }

    protected function familyExists(string $name): bool
    {
        return array_any($this->registry->getMetricFamilySamples(), fn ($family): bool => $family->getName() === $name);
    }
}
