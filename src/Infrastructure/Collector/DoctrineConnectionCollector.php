<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Collector;

use MaxShamaev\MetricsBundle\Infrastructure\Enum\DoctrineQueryTypeEnum;
use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Throwable;

class DoctrineConnectionCollector extends AbstractCollector
{
    use LoggerCollectorTrait;

    private const DEFAULT_TABLE_NAME = 'unknown';

    public function incQueryExecute(string $connection, DoctrineQueryTypeEnum $type, ?string $table): void
    {
        $metric = $this->repository->find(MetricLabelEnum::DOCTRINE_QUERY_EXECUTE);

        try {
            $counter = $this->registry->getOrRegisterCounter(
                $this->namespace,
                $metric->name->value,
                $metric->description,
                $metric->getLabelNames(),
            );

            $counter->inc($this->prepareLabelValues(
                [$connection, $type->value, $table ?? self::DEFAULT_TABLE_NAME],
            ));
        } catch (Throwable $e) {
            $this->processException($e, $metric->name->value);
        }
    }

    public function setQueryExecuteDuration(
        string $connection,
        DoctrineQueryTypeEnum $type,
        ?string $table,
        float $duration,
    ): void {
        $metric = $this->repository->find(MetricLabelEnum::DOCTRINE_QUERY_DURATION_HISTOGRAM_SECONDS);

        try {
            $histogram = $this->registry->getOrRegisterHistogram(
                $this->namespace,
                $metric->name->value,
                $metric->description,
                $metric->getLabelNames(),
                $metric->batches,
            );

            $histogram->observe(
                $duration,
                $this->prepareLabelValues([$connection, $type->value, $table ?? self::DEFAULT_TABLE_NAME]),
            );
        } catch (Throwable $e) {
            $this->processException($e, $metric->name->value);
        }
    }
}
