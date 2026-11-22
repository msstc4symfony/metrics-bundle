<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Framework\Monolog;

use Msstc4Symfony\MetricsBundle\Application\Collector\ErrorCollector;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricDefinitionInterface;
use Msstc4Symfony\MetricsBundle\Application\Port\MetricRecorderInterface;
use Msstc4Symfony\MetricsBundle\Framework\Monolog\HandlerDecorator;
use Msstc4Symfony\MetricsBundle\Test\Support\CollectingLogger;
use Msstc4Symfony\MetricsBundle\Test\Support\ThrowingMetricRecorder;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(HandlerDecorator::class)]
#[UsesClass(ErrorCollector::class)]
final class HandlerDecoratorReentrancyTest extends TestCase
{
    /**
     * Second line of defence if the metrics channel ever reaches the decorator: a recorder that logs its failure
     * through the same decorator must not re-enter the collector.
     */
    public function testARecordingThatLogsDoesNotReenterTheCollector(): void
    {
        $inner = new CollectingLogger();
        $recorder = new class implements MetricRecorderInterface {
            public int $increments = 0;

            public ?LoggerInterface $logger = null;

            #[Override]
            public function increment(MetricDefinitionInterface $metric, array $labels = []): void
            {
                $this->increments++;
                $this->logger?->error('Cannot save metric');
            }

            #[Override]
            public function set(MetricDefinitionInterface $metric, float|int $value, array $labels = []): void
            {
            }

            #[Override]
            public function observe(MetricDefinitionInterface $metric, float $value, array $labels = []): void
            {
            }
        };
        $decorator = new HandlerDecorator($inner, new ErrorCollector($recorder, false, new CollectingLogger()));
        $recorder->logger = $decorator;

        $decorator->error('boom');

        self::assertSame(1, $recorder->increments);
        self::assertSame(['error: Cannot save metric', 'error: boom'], $inner->records);

        $decorator->error('again');

        self::assertSame(2, $recorder->increments, 'the guard is released after counting');
    }

    public function testARecorderFailureNeitherEscapesNorSkipsTheInnerLog(): void
    {
        $inner = new CollectingLogger();
        $recorder = new ThrowingMetricRecorder();
        $decorator = new HandlerDecorator($inner, new ErrorCollector($recorder, false, new CollectingLogger()));

        $decorator->error('first');
        $decorator->warning('second');

        self::assertSame(['error: first', 'warning: second'], $inner->records);
        self::assertSame(2, $recorder->increments, 'the guard is released after a failed count');
    }
}
