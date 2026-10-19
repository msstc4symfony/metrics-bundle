<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Framework\Monolog;

use Monolog\Level;
use Msstc4Symfony\MetricsBundle\Application\Collector\ErrorCollector;
use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricRepository;
use Msstc4Symfony\MetricsBundle\Framework\Monolog\HandlerDecorator;
use Msstc4Symfony\MetricsBundle\Infrastructure\Prometheus\PrometheusMetricRecorder;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;
use Override;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;
use Psr\Log\LoggerInterface;

final class HandlerDecoratorTest extends TestCase
{
    private CollectorRegistry $registry;

    private ErrorCollector $collector;

    private LoggerInterface&MockObject $inner;

    #[Override]
    protected function setUp(): void
    {
        if (!class_exists(Level::class)) {
            self::markTestSkipped('monolog/monolog not installed');
        }

        $this->registry = new CollectorRegistry(new InMemory());
        $this->collector = new ErrorCollector(new PrometheusMetricRecorder($this->registry, new MetricRepository([]), 'app', 'cmp'), false);
        $this->inner = $this->createMock(LoggerInterface::class);
    }

    public function testEmergencyIsRecordedAndForwarded(): void
    {
        $this->inner->expects(self::once())->method('emergency')->with('boom', ['k' => 'v']);

        $this->decorator()->emergency('boom', ['k' => 'v']);

        self::assertSame(
            [['app', 'cmp', 'EMERGENCY']],
            RegistrySamples::labels($this->registry, BuiltInMetric::ERROR),
        );
    }

    public function testInfoIsForwardedButNotRecorded(): void
    {
        $this->inner->expects(self::once())->method('info')->with('hello');

        $this->decorator()->info('hello');

        self::assertFalse(RegistrySamples::exists($this->registry, BuiltInMetric::ERROR));
    }

    public function testDebugIsForwardedButNotRecorded(): void
    {
        $this->inner->expects(self::once())->method('debug')->with('debug-msg');

        $this->decorator()->debug('debug-msg');

        self::assertFalse(RegistrySamples::exists($this->registry, BuiltInMetric::ERROR));
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
            RegistrySamples::labels($this->registry, BuiltInMetric::ERROR),
        );
    }

    public function testLogWithIntLevelMapsThroughMonologToLevel(): void
    {
        $this->inner->expects(self::once())->method('log');

        // Monolog Level::Warning = 300
        $this->decorator()->log(300, 'something');

        self::assertSame(
            [['app', 'cmp', 'WARNING']],
            RegistrySamples::labels($this->registry, BuiltInMetric::ERROR),
        );
    }

    public function testLogWithUnknownStringLevelDoesNotThrow(): void
    {
        $this->inner->expects(self::once())->method('log');

        $this->decorator()->log('not-a-real-level', 'something');

        self::assertFalse(RegistrySamples::exists($this->registry, BuiltInMetric::ERROR));
    }

    /**
     * @param list<Level>|null $allowedLevels
     */
    private function decorator(?array $allowedLevels = null): HandlerDecorator
    {
        return $allowedLevels === null
            ? new HandlerDecorator($this->inner, $this->collector)
            : new HandlerDecorator($this->inner, $this->collector, $allowedLevels);
    }
}
