<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Test\Unit\Infrastructure\Collector;

use MaxShamaev\MetricsBundle\Infrastructure\Collector\ConsoleCollector;
use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use MaxShamaev\MetricsBundle\Infrastructure\Repository\MetricRepository;
use PHPUnit\Framework\Attributes\DataProvider;

final class ConsoleCollectorTest extends CollectorTestCase
{
    public function testIncStartLabelsByCommand(): void
    {
        $this->build()->incConsoleCommandStart('app:run');

        self::assertSame(
            [['app', 'cmp', 'app:run']],
            $this->labelValuesFor('symfony_' . MetricLabelEnum::CONSOLE_COMMAND_START->value),
        );
    }

    public function testIncFinishLabelsByCommand(): void
    {
        $this->build()->incConsoleCommandFinish('app:run');

        self::assertSame(
            [['app', 'cmp', 'app:run']],
            $this->labelValuesFor('symfony_' . MetricLabelEnum::CONSOLE_COMMAND_FINISH->value),
        );
    }

    public function testSetCommandDurationObserved(): void
    {
        $this->build()->setCommandDuration('app:run', 0.42);

        self::assertTrue($this->familyExists('symfony_' . MetricLabelEnum::CONSOLE_COMMAND_DURATION_HISTOGRAM_SECONDS->value));
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

        self::assertFalse($this->familyExists('symfony_' . MetricLabelEnum::CONSOLE_COMMAND_START->value));
        self::assertFalse($this->familyExists('symfony_' . MetricLabelEnum::CONSOLE_COMMAND_FINISH->value));
        self::assertFalse($this->familyExists('symfony_' . MetricLabelEnum::CONSOLE_COMMAND_DURATION_HISTOGRAM_SECONDS->value));
    }

    private function build(): ConsoleCollector
    {
        return new ConsoleCollector($this->registry, new MetricRepository([]), 'app', 'cmp');
    }
}
