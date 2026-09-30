<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Infrastructure\Monolog\Handler;

use Monolog\Level;
use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\ErrorCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Monolog\Handler\HandlerDecorator;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\MetricFamilySamples;
use Prometheus\Sample;
use Prometheus\Storage\InMemory;
use Psr\Log\LoggerInterface;

final class HandlerDecoratorTest extends TestCase
{
    private CollectorRegistry $registry;

    private ErrorCollector $collector;

    private LoggerInterface&MockObject $inner;

    protected function setUp(): void
    {
        if (!class_exists(Level::class)) {
            self::markTestSkipped('monolog/monolog not installed');
        }

        $this->registry = new CollectorRegistry(new InMemory());
        $this->collector = new ErrorCollector($this->registry, new MetricRepository([]), 'app', 'cmp');
        $this->inner = $this->createMock(LoggerInterface::class);
    }

    public function testEmergencyIsRecordedAndForwarded(): void
    {
        $this->inner->expects(self::once())->method('emergency')->with('boom', ['k' => 'v']);

        $this->decorator()->emergency('boom', ['k' => 'v']);

        self::assertSame(
            [['app', 'cmp', 'EMERGENCY']],
            $this->labelValuesFor('symfony_' . MetricLabelEnum::ERROR->value),
        );
    }

    public function testInfoIsForwardedButNotRecorded(): void
    {
        $this->inner->expects(self::once())->method('info')->with('hello');

        $this->decorator()->info('hello');

        self::assertFalse($this->familyExists('symfony_' . MetricLabelEnum::ERROR->value));
    }

    public function testDebugIsForwardedButNotRecorded(): void
    {
        $this->inner->expects(self::once())->method('debug')->with('debug-msg');

        $this->decorator()->debug('debug-msg');

        self::assertFalse($this->familyExists('symfony_' . MetricLabelEnum::ERROR->value));
    }

    public function testCustomAllowedLevelsRestrictRecording(): void
    {
        $this->inner->expects(self::once())->method('warning');
        $this->inner->expects(self::once())->method('error');

        // Only Error allowed → warning is forwarded but NOT recorded.
        $decorator = $this->decorator(allowedLevels: [Level::Error]);
        $decorator->warning('w');
        $decorator->error('e');

        self::assertSame(
            [['app', 'cmp', 'ERROR']],
            $this->labelValuesFor('symfony_' . MetricLabelEnum::ERROR->value),
        );
    }

    public function testLogWithIntLevelMapsThroughMonologToLevel(): void
    {
        $this->inner->expects(self::once())->method('log');

        // Monolog Level::Warning = 300
        $this->decorator()->log(300, 'something');

        self::assertSame(
            [['app', 'cmp', 'WARNING']],
            $this->labelValuesFor('symfony_' . MetricLabelEnum::ERROR->value),
        );
    }

    public function testLogWithUnknownStringLevelDoesNotThrow(): void
    {
        $this->inner->expects(self::once())->method('log');

        $this->decorator()->log('not-a-real-level', 'something');

        self::assertFalse($this->familyExists('symfony_' . MetricLabelEnum::ERROR->value));
    }

    /**
     * @param Level[]|null $allowedLevels
     */
    private function decorator(?array $allowedLevels = null): HandlerDecorator
    {
        return $allowedLevels === null
            ? new HandlerDecorator($this->inner, $this->collector)
            : new HandlerDecorator($this->inner, $this->collector, $allowedLevels);
    }

    /**
     * @return array<array<int, string>>
     */
    private function labelValuesFor(string $name): array
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

    private function familyExists(string $name): bool
    {
        return array_any($this->registry->getMetricFamilySamples(), fn (MetricFamilySamples $family): bool => $family->getName() === $name);
    }
}
