<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Collector;

use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Throwable;

class ConsoleCollector extends AbstractCollector
{
    use LoggerCollectorTrait;

    /**
     * @var string[]
     */
    private array $ignoredCommands = [
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

        $metric = $this->repository->find(MetricLabelEnum::CONSOLE_COMMAND_START);

        try {
            $counter = $this->registry->getOrRegisterCounter(
                $this->namespace,
                $metric->name->value,
                $metric->description,
                $metric->getLabelNames(),
            );

            $counter->inc($this->prepareLabelValues([$command]));
        } catch (Throwable $e) {
            $this->processException($e, $metric->name->value);
        }
    }

    public function incConsoleCommandFinish(string $command): void
    {
        if (!$this->isAllowedMeasureCommand($command)) {
            return;
        }

        $metric = $this->repository->find(MetricLabelEnum::CONSOLE_COMMAND_FINISH);

        try {
            $counter = $this->registry->getOrRegisterCounter(
                $this->namespace,
                $metric->name->value,
                $metric->description,
                $metric->getLabelNames(),
            );

            $counter->inc($this->prepareLabelValues([$command]));
        } catch (Throwable $e) {
            $this->processException($e, $metric->name->value);
        }
    }

    public function setCommandDuration(string $command, float $duration): void
    {
        if (!$this->isAllowedMeasureCommand($command)) {
            return;
        }

        $metric = $this->repository->find(MetricLabelEnum::CONSOLE_COMMAND_DURATION_HISTOGRAM_SECONDS);

        try {
            $histogram = $this->registry->getOrRegisterHistogram(
                $this->namespace,
                $metric->name->value,
                $metric->description,
                $metric->getLabelNames(),
                $metric->batches,
            );

            $histogram->observe($duration, $this->prepareLabelValues([$command]));
        } catch (Throwable $e) {
            $this->processException($e, $metric->name->value);
        }
    }

    private function isAllowedMeasureCommand(string $command): bool
    {
        return !in_array($command, $this->ignoredCommands, true)
            && array_filter(
                $this->ignoredCommands,
                static fn (string $pattern): bool => str_starts_with($command, $pattern),
            ) === [];
    }
}
