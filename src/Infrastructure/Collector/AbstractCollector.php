<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Collector;

use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnumInterface;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepositoryInterface;
use Prometheus\RegistryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\Attribute\Required;
use Throwable;

abstract class AbstractCollector
{
    private const string DEFAULT_NAMESPACE = 'symfony';

    protected string $namespace = self::DEFAULT_NAMESPACE;

    protected ?LoggerInterface $logger = null;

    /**
     * @var string[]
     */
    private readonly array $labelPrefix;

    public function __construct(
        protected readonly RegistryInterface $registry,
        protected readonly MetricRepositoryInterface $repository,
        protected readonly string $applicationName,
        protected readonly string $componentName,
    ) {
        $this->labelPrefix = [$this->applicationName, $this->componentName];
    }

    #[Required]
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * @param scalar[] $labels
     */
    protected function incCounter(MetricLabelEnumInterface $enum, array $labels = []): void
    {
        $metric = $this->repository->find($enum);

        try {
            $counter = $this->registry->getOrRegisterCounter(
                $this->namespace,
                $metric->name->value,
                $metric->description,
                $metric->labelNames,
            );

            $counter->inc($this->prepareLabelValues($labels));
        } catch (Throwable $e) {
            $this->processException($e, $metric->name->value);
        }
    }

    /**
     * @param scalar[] $labels
     */
    protected function setGauge(MetricLabelEnumInterface $enum, float|int $value, array $labels = []): void
    {
        $metric = $this->repository->find($enum);

        try {
            $gauge = $this->registry->getOrRegisterGauge(
                $this->namespace,
                $metric->name->value,
                $metric->description,
                $metric->labelNames,
            );

            $gauge->set((float) $value, $this->prepareLabelValues($labels));
        } catch (Throwable $e) {
            $this->processException($e, $metric->name->value);
        }
    }

    /**
     * @param scalar[] $labels
     */
    protected function observeHistogram(MetricLabelEnumInterface $enum, float $value, array $labels = []): void
    {
        $metric = $this->repository->find($enum);

        try {
            $histogram = $this->registry->getOrRegisterHistogram(
                $this->namespace,
                $metric->name->value,
                $metric->description,
                $metric->labelNames,
                $metric->batches,
            );

            $histogram->observe($value, $this->prepareLabelValues($labels));
        } catch (Throwable $e) {
            $this->processException($e, $metric->name->value);
        }
    }

    /**
     * @param scalar[] $labels
     * @param float[] $quantiles
     */
    protected function observeSummary(
        MetricLabelEnumInterface $enum,
        float $value,
        array $labels = [],
        array $quantiles = [0.5, 0.9, 0.95, 0.99],
        int $maxAgeSeconds = 86400,
    ): void {
        $metric = $this->repository->find($enum);

        try {
            $summary = $this->registry->getOrRegisterSummary(
                $this->namespace,
                $metric->name->value,
                $metric->description,
                $metric->labelNames,
                $maxAgeSeconds,
                $quantiles,
            );

            $summary->observe($value, $this->prepareLabelValues($labels));
        } catch (Throwable $e) {
            $this->processException($e, $metric->name->value);
        }
    }

    /**
     * @param scalar[] $values
     *
     * @return string[]
     */
    protected function prepareLabelValues(array $values = []): array
    {
        return array_merge(
            $this->labelPrefix,
            array_map(strval(...), $values),
        );
    }

    protected function processException(Throwable $exception, int|string $metricName): void
    {
        if ($this->logger instanceof LoggerInterface) {
            $this->logger->error(
                'Cannot save metric "' . $metricName . '": ' . $exception->getMessage(),
                ['exception' => $exception],
            );
        }
    }
}
