<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Prometheus;

use InvalidArgumentException;
use Monolog\Attribute\WithMonologChannel;
use Msstc4Symfony\MetricsBundle\Application\LogChannel;
use Msstc4Symfony\MetricsBundle\Application\Metric\Metric;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricDefinitionInterface;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricRepositoryInterface;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricType;
use Msstc4Symfony\MetricsBundle\Application\Port\MetricRecorderInterface;
use Override;
use Prometheus\RegistryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @internal
 */
#[WithMonologChannel(LogChannel::NAME)]
final readonly class PrometheusMetricRecorder implements MetricRecorderInterface
{
    private const NAMESPACE = 'symfony';

    private const SUMMARY_QUANTILES = [0.5, 0.9, 0.95, 0.99];

    private const SUMMARY_MAX_AGE_SECONDS = 86400;

    public function __construct(
        private RegistryInterface $registry,
        private MetricRepositoryInterface $repository,
        private string $applicationName,
        private string $componentName,
        private ?LoggerInterface $logger = null,
    ) {
    }

    #[Override]
    public function increment(MetricDefinitionInterface $metric, array $labels = []): void
    {
        $definition = $this->definition($metric, 'increment', [MetricType::COUNTER]);
        $this->guarded($definition, fn () => $this->registry
            ->getOrRegisterCounter(self::NAMESPACE, ...$this->identity($definition))
            ->inc($this->labelValues($labels)));
    }

    #[Override]
    public function set(MetricDefinitionInterface $metric, float|int $value, array $labels = []): void
    {
        $definition = $this->definition($metric, 'set', [MetricType::GAUGE]);
        $this->guarded($definition, fn () => $this->registry
            ->getOrRegisterGauge(self::NAMESPACE, ...$this->identity($definition))
            ->set((float) $value, $this->labelValues($labels)));
    }

    #[Override]
    public function observe(MetricDefinitionInterface $metric, float $value, array $labels = []): void
    {
        $definition = $this->definition($metric, 'observe', [MetricType::HISTOGRAM, MetricType::SUMMARY]);
        $this->guarded($definition, fn () => $definition->type === MetricType::HISTOGRAM
            ? $this->observeHistogram($definition, $value, $labels)
            : $this->observeSummary($definition, $value, $labels));
    }

    /**
     * @param list<scalar> $labels
     */
    private function observeHistogram(Metric $definition, float $value, array $labels): void
    {
        $this->registry
            ->getOrRegisterHistogram(self::NAMESPACE, ...[...$this->identity($definition), $definition->batches])
            ->observe($value, $this->labelValues($labels))
        ;
    }

    /**
     * @param list<scalar> $labels
     */
    private function observeSummary(Metric $definition, float $value, array $labels): void
    {
        $this->registry
            ->getOrRegisterSummary(self::NAMESPACE, ...[...$this->identity($definition), self::SUMMARY_MAX_AGE_SECONDS, self::SUMMARY_QUANTILES])
            ->observe($value, $this->labelValues($labels))
        ;
    }

    /**
     * Name, help and label names, as every getOrRegister*() takes them after the namespace.
     *
     * @return array{string, string, list<string>}
     */
    private function identity(Metric $definition): array
    {
        return [(string) $definition->name->value, $definition->description, $definition->labelNames];
    }

    /**
     * @param non-empty-list<MetricType> $types
     */
    private function definition(MetricDefinitionInterface $metric, string $method, array $types): Metric
    {
        $definition = $this->repository->find($metric);
        if (!\in_array($definition->type, $types, true)) {
            throw new InvalidArgumentException(\sprintf('%s() needs a %s, "%s" is a %s', $method, implode(' or ', array_map(static fn (MetricType $type): string => $type->value, $types)), $metric->value, $definition->type->value));
        }

        return $definition;
    }

    /**
     * @param list<scalar> $labels
     *
     * @return list<string>
     */
    private function labelValues(array $labels): array
    {
        return [$this->applicationName, $this->componentName, ...array_map(strval(...), $labels)];
    }

    /**
     * @param callable(): void $write
     */
    private function guarded(Metric $definition, callable $write): void
    {
        try {
            $write();
        } catch (Throwable $exception) {
            try {
                $this->logger?->error(
                    'Cannot save metric "' . $definition->name->value . '": ' . $exception->getMessage(),
                    ['exception' => $exception],
                );
            } catch (Throwable) {
                // Logger failures must not break the application.
            }
        }
    }
}
