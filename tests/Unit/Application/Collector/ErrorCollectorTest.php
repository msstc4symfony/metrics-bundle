<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Application\Collector;

use Msstc4Symfony\MetricsBundle\Application\Collector\ErrorCollector;
use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Test\Support\CollectingLogger;
use Msstc4Symfony\MetricsBundle\Test\Support\ThrowingMetricRecorder;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(ErrorCollector::class)]
final class ErrorCollectorTest extends RecorderTestCase
{
    public function testIncExceptionRegistersFqcnLabel(): void
    {
        $this->build(useShort: false)->incException(new RuntimeException('boom'));

        self::assertSame([['increment', BuiltInMetric::EXCEPTION, null, [RuntimeException::class]]], $this->recorder->calls);
    }

    public function testIncExceptionWithShortClassNameStripsNamespace(): void
    {
        $this->build(useShort: true)->incException(new RuntimeException('boom'));

        self::assertSame([['increment', BuiltInMetric::EXCEPTION, null, ['RuntimeException']]], $this->recorder->calls);
    }

    public function testIgnoredExceptionsAreSkipped(): void
    {
        $this->build()->incException(new NotFoundHttpException());

        self::assertSame([], $this->recorder->calls);
    }

    public function testIncErrorLabelsByLevelName(): void
    {
        $this->build()->incError('ERROR');

        self::assertSame([['increment', BuiltInMetric::ERROR, null, ['ERROR']]], $this->recorder->calls);
    }

    public function testARecorderFailureOnExceptionIsLoggedAndSwallowed(): void
    {
        $logger = new CollectingLogger();
        $recorder = new ThrowingMetricRecorder();

        (new ErrorCollector($recorder, false, $logger))->incException(new RuntimeException('boom'));

        self::assertSame(1, $recorder->increments);
        self::assertSame(['error: Cannot record exception metric'], $logger->records);
        self::assertInstanceOf(RuntimeException::class, $logger->contexts[0]['exception'] ?? null);
    }

    public function testARecorderFailureOnErrorIsLoggedAndSwallowed(): void
    {
        $logger = new CollectingLogger();
        $recorder = new ThrowingMetricRecorder();

        (new ErrorCollector($recorder, false, $logger))->incError('ERROR');

        self::assertSame(1, $recorder->increments);
        self::assertSame(['error: Cannot record error metric'], $logger->records);
    }

    public function testALoggerFailureOnTopOfARecorderFailureIsSwallowed(): void
    {
        $logger = new class extends AbstractLogger {
            #[Override]
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException('logger failure');
            }
        };
        $collector = new ErrorCollector(new ThrowingMetricRecorder(), false, $logger);

        $collector->incError('ERROR');
        $collector->incException(new RuntimeException('boom'));

        $this->expectNotToPerformAssertions();
    }

    private function build(bool $useShort = false): ErrorCollector
    {
        return new ErrorCollector($this->recorder, $useShort, new CollectingLogger());
    }
}
