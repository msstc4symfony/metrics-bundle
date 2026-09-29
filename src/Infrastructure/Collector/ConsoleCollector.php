<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Collector;

use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;

final class ConsoleCollector extends AbstractCollector
{
    private const array IGNORED_COMMANDS = [
        'metrics:',
        'healthcheck:',
        'list',
        'lint:',
        'make:',
        'debug:',
        'doctrine:',
        'assets:',
        'cache:',
        'about',
    ];

    public function incConsoleCommandStart(string $command): void
    {
        if (!$this->isAllowedMeasureCommand($command)) {
            return;
        }

        $this->incCounter(MetricLabelEnum::CONSOLE_COMMAND_START, [$command]);
    }

    public function incConsoleCommandFinish(string $command): void
    {
        if (!$this->isAllowedMeasureCommand($command)) {
            return;
        }

        $this->incCounter(MetricLabelEnum::CONSOLE_COMMAND_FINISH, [$command]);
    }

    public function setCommandDuration(string $command, float $duration): void
    {
        if (!$this->isAllowedMeasureCommand($command)) {
            return;
        }

        $this->observeHistogram(MetricLabelEnum::CONSOLE_COMMAND_DURATION_HISTOGRAM_SECONDS, $duration, [$command]);
    }

    private function isAllowedMeasureCommand(string $command): bool
    {
        return !array_any(
            self::IGNORED_COMMANDS,
            static fn (string $pattern): bool => $command === $pattern || str_starts_with($command, $pattern),
        );
    }
}
