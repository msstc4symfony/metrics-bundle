<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Application\Collector;

use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Application\Port\MetricRecorderInterface;

final readonly class DoctrineConnectionCollector
{
    private const DEFAULT_TABLE_NAME = 'unknown';

    public function __construct(
        private MetricRecorderInterface $recorder,
    ) {
    }

    public function incQueryExecute(string $connection, DoctrineQueryType $type, ?string $table): void
    {
        $this->recorder->increment(
            BuiltInMetric::DOCTRINE_QUERY_EXECUTE,
            [$connection, $type->value, $table ?? self::DEFAULT_TABLE_NAME],
        );
    }

    public function setQueryExecuteDuration(
        string $connection,
        DoctrineQueryType $type,
        ?string $table,
        float $duration,
    ): void {
        $this->recorder->observe(
            BuiltInMetric::DOCTRINE_QUERY_DURATION_HISTOGRAM_SECONDS,
            $duration,
            [$connection, $type->value, $table ?? self::DEFAULT_TABLE_NAME],
        );
    }
}
