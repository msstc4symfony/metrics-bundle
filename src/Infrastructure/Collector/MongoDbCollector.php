<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Collector;

use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Throwable;

class MongoDbCollector extends AbstractCollector
{
    use LoggerCollectorTrait;

    public function incCommandSuccess(string $command, string $host): void
    {
        $metric = $this->repository->find(MetricLabelEnum::MONGODB_COMMAND_SUCCESS);

        try {
            $counter = $this->registry->getOrRegisterCounter(
                $this->namespace,
                $metric->name->value,
                $metric->description,
                $metric->getLabelNames(),
            );

            $counter->inc($this->prepareLabelValues([$command, $host]));
        } catch (Throwable $e) {
            $this->processException($e, $metric->name->value);
        }
    }

    public function incCommandFailed(string $command, string $host): void
    {
        $metric = $this->repository->find(MetricLabelEnum::MONGODB_COMMAND_FAILED);

        try {
            $counter = $this->registry->getOrRegisterCounter(
                $this->namespace,
                $metric->name->value,
                $metric->description,
                $metric->getLabelNames(),
            );

            $counter->inc($this->prepareLabelValues([$command, $host]));
        } catch (Throwable $e) {
            $this->processException($e, $metric->name->value);
        }
    }

    public function setCommandDuration(string $command, string $host, float $duration): void
    {
        $metric = $this->repository->find(MetricLabelEnum::MONGODB_COMMAND_DURATION_HISTOGRAM_SECONDS);

        try {
            $histogram = $this->registry->getOrRegisterHistogram(
                $this->namespace,
                $metric->name->value,
                $metric->description,
                $metric->getLabelNames(),
                $metric->batches,
            );

            $histogram->observe($duration, $this->prepareLabelValues([$command, $host]));
        } catch (Throwable $e) {
            $this->processException($e, $metric->name->value);
        }
    }
}
