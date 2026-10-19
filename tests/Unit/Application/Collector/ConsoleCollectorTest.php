<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Application\Collector;

use Msstc4Symfony\MetricsBundle\Application\Collector\ConsoleCollector;
use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(ConsoleCollector::class)]
final class ConsoleCollectorTest extends RecorderTestCase
{
    public function testIncStartLabelsByCommand(): void
    {
        $this->build()->incConsoleCommandStart('app:run');

        self::assertSame([['increment', BuiltInMetric::CONSOLE_COMMAND_START, null, ['app:run']]], $this->recorder->calls);
    }

    public function testIncFinishLabelsByCommand(): void
    {
        $this->build()->incConsoleCommandFinish('app:run');

        self::assertSame([['increment', BuiltInMetric::CONSOLE_COMMAND_FINISH, null, ['app:run']]], $this->recorder->calls);
    }

    public function testSetCommandDurationObserved(): void
    {
        $this->build()->setCommandDuration('app:run', 0.42);

        self::assertSame([['observe', BuiltInMetric::CONSOLE_COMMAND_DURATION_HISTOGRAM_SECONDS, 0.42, ['app:run']]], $this->recorder->calls);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ignoredCommandProvider(): iterable
    {
        yield 'list (exact)' => ['list'];
        yield 'about (exact)' => ['about'];
        yield 'metrics: prefix' => ['metrics:list'];
        yield 'doctrine: prefix' => ['doctrine:migrations:status'];
        yield 'cache: prefix' => ['cache:clear'];
        yield 'debug: prefix' => ['debug:router'];
    }

    #[DataProvider('ignoredCommandProvider')]
    public function testIgnoredCommandsAreNotMeasured(string $command): void
    {
        $collector = $this->build();
        $collector->incConsoleCommandStart($command);
        $collector->incConsoleCommandFinish($command);
        $collector->setCommandDuration($command, 0.1);

        self::assertSame([], $this->recorder->calls);
    }

    private function build(): ConsoleCollector
    {
        return new ConsoleCollector($this->recorder);
    }
}
