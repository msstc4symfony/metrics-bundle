<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Collector;

use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\DoctrineQueryTypeEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;

final class DoctrineConnectionCollector extends AbstractCollector
{
    private const string DEFAULT_TABLE_NAME = 'unknown';

    public function incQueryExecute(string $connection, DoctrineQueryTypeEnum $type, ?string $table): void
    {
        $this->incCounter(
            MetricLabelEnum::DOCTRINE_QUERY_EXECUTE,
            [$connection, $type->value, $table ?? self::DEFAULT_TABLE_NAME],
        );
    }

    public function setQueryExecuteDuration(
        string $connection,
        DoctrineQueryTypeEnum $type,
        ?string $table,
        float $duration,
    ): void {
        $this->observeHistogram(
            MetricLabelEnum::DOCTRINE_QUERY_DURATION_HISTOGRAM_SECONDS,
            $duration,
            [$connection, $type->value, $table ?? self::DEFAULT_TABLE_NAME],
        );
    }
}
