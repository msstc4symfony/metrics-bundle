<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Collector;

use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnumInterface;
use MaxShamaev\MetricsBundle\Infrastructure\Repository\MetricRepositoryInterface;
use Prometheus\RegistryInterface;
use Throwable;

abstract class AbstractCollector
{
    private const DEFAULT_NAMESPACE = 'symfony';

    protected string $namespace = self::DEFAULT_NAMESPACE;

    /**
     * @var string[]|null
     */
    private ?array $labelPrefix = null;

    public function __construct(
        protected readonly RegistryInterface $registry,
        protected readonly MetricRepositoryInterface $repository,
        protected readonly string $applicationName,
        protected readonly string $componentName,
    ) {
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
                $metric->getLabelNames(),
            );

            $counter->inc($this->prepareLabelValues($labels));
        } catch (Throwable $e) {
            if (method_exists($this, 'processException')) {
                $this->processException($e, $metric->name->value);
            }
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
            $this->labelPrefix ??= [$this->applicationName, $this->componentName, $this->getContainerId()],
            array_map(strval(...), $values),
        );
    }

    protected function getContainerId(): string
    {
        if (isset($_ENV['POD_NAME'])) {
            return (string) $_ENV['POD_NAME'];
        }

        if (isset($_ENV['POD_UID'])) {
            return (string) $_ENV['POD_UID'];
        }

        $hostname = gethostname();

        return is_string($hostname) ? $hostname : 'unknown';
    }
}
