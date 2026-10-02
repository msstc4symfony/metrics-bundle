<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Infrastructure\Collector;

use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\ConsoleCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepository;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;
use PHPUnit\Framework\Attributes\DataProvider;

final class ConsoleCollectorTest extends CollectorTestCase
{
    public function testIncStartLabelsByCommand(): void
    {
        $this->build()->incConsoleCommandStart('app:run');

        self::assertSame(
            [['app', 'cmp', 'app:run']],
            RegistrySamples::labels($this->registry, MetricLabelEnum::CONSOLE_COMMAND_START),
        );
    }

    public function testIncFinishLabelsByCommand(): void
    {
        $this->build()->incConsoleCommandFinish('app:run');

        self::assertSame(
            [['app', 'cmp', 'app:run']],
            RegistrySamples::labels($this->registry, MetricLabelEnum::CONSOLE_COMMAND_FINISH),
        );
    }

    public function testSetCommandDurationObserved(): void
    {
        $this->build()->setCommandDuration('app:run', 0.42);

        self::assertTrue(RegistrySamples::exists($this->registry, MetricLabelEnum::CONSOLE_COMMAND_DURATION_HISTOGRAM_SECONDS));
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

        self::assertFalse(RegistrySamples::exists($this->registry, MetricLabelEnum::CONSOLE_COMMAND_START));
        self::assertFalse(RegistrySamples::exists($this->registry, MetricLabelEnum::CONSOLE_COMMAND_FINISH));
        self::assertFalse(RegistrySamples::exists($this->registry, MetricLabelEnum::CONSOLE_COMMAND_DURATION_HISTOGRAM_SECONDS));
    }

    private function build(): ConsoleCollector
    {
        return new ConsoleCollector($this->registry, new MetricRepository([]), 'app', 'cmp');
    }
}
