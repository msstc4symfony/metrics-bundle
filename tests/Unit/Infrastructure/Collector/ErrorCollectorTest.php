<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Infrastructure\Collector;

use Monolog\Level;
use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\ErrorCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepository;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\RegistryInterface;
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
            RegistrySamples::labels($registry, MetricLabelEnum::EXCEPTION),
        );
    }

    public function testIncExceptionWithShortClassNameStripsNamespace(): void
    {
        $registry = new CollectorRegistry(new InMemory());
        $collector = $this->build($registry, useShort: true);

        $collector->incException(new RuntimeException('boom'));

        self::assertSame(
            [['app', 'cmp', 'RuntimeException']],
            RegistrySamples::labels($registry, MetricLabelEnum::EXCEPTION),
        );
    }

    public function testIgnoredExceptionsAreSkipped(): void
    {
        $registry = new CollectorRegistry(new InMemory());
        $collector = $this->build($registry);

        $collector->incException(new NotFoundHttpException());

        self::assertSame([], RegistrySamples::labels($registry, MetricLabelEnum::EXCEPTION));
    }

    public function testIncErrorLabelsByLevelName(): void
    {
        $registry = new CollectorRegistry(new InMemory());
        $collector = $this->build($registry);

        $collector->incError(Level::Error);

        self::assertSame(
            [['app', 'cmp', 'ERROR']],
            RegistrySamples::labels($registry, MetricLabelEnum::ERROR),
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
}
