<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Infrastructure\Collector;

use Monolog\Level;
use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\ErrorCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepository;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\RegistryInterface;
use Prometheus\Sample;
use Prometheus\Storage\InMemory;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ErrorCollectorTest extends TestCase
{
    public function testIncExceptionRegistersFqcnLabel(): void
    {
        $registry = new CollectorRegistry(new InMemory());
        $collector = $this->build($registry, useShort: false);

        $collector->incException(new RuntimeException('boom'));

        self::assertSame(
            [['app', 'cmp', RuntimeException::class]],
            $this->labelValuesFor($registry, 'symfony_' . MetricLabelEnum::EXCEPTION->value),
        );
    }

    public function testIncExceptionWithShortClassNameStripsNamespace(): void
    {
        $registry = new CollectorRegistry(new InMemory());
        $collector = $this->build($registry, useShort: true);

        $collector->incException(new RuntimeException('boom'));

        self::assertSame(
            [['app', 'cmp', 'RuntimeException']],
            $this->labelValuesFor($registry, 'symfony_' . MetricLabelEnum::EXCEPTION->value),
        );
    }

    public function testIgnoredExceptionsAreSkipped(): void
    {
        $registry = new CollectorRegistry(new InMemory());
        $collector = $this->build($registry);

        $collector->incException(new NotFoundHttpException());

        self::assertSame([], $this->labelValuesFor($registry, 'symfony_' . MetricLabelEnum::EXCEPTION->value));
    }

    public function testIncErrorLabelsByLevelName(): void
    {
        $registry = new CollectorRegistry(new InMemory());
        $collector = $this->build($registry);

        $collector->incError(Level::Error);

        self::assertSame(
            [['app', 'cmp', 'ERROR']],
            $this->labelValuesFor($registry, 'symfony_' . MetricLabelEnum::ERROR->value),
        );
    }

    public function testIncExceptionSwallowsRegistryFailure(): void
    {
        $registry = self::createStub(RegistryInterface::class);
        $registry->method('getOrRegisterCounter')->willThrowException(new RuntimeException('storage gone'));

        $collector = new ErrorCollector($registry, new MetricRepository([]), 'app', 'cmp', false);

        $this->expectNotToPerformAssertions();
        $collector->incException(new RuntimeException('boom'));
    }

    private function build(CollectorRegistry $registry, bool $useShort = false): ErrorCollector
    {
        return new ErrorCollector($registry, new MetricRepository([]), 'app', 'cmp', $useShort);
    }

    /**
     * @return array<array<int, string>>
     */
    private function labelValuesFor(CollectorRegistry $registry, string $name): array
    {
        foreach ($registry->getMetricFamilySamples() as $family) {
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
}
