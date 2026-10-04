<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics;

use Closure;
use Doctrine\DBAL\Driver\Result as ResultInterface;
use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\DoctrineConnectionCollector;

/** @internal Shared by the connection and statement middlewares. */
final readonly class QueryMeter
{
    public function __construct(
        private DoctrineConnectionCollector $collector,
        private string $connectionName,
        private QueryLabelling $labeller,
    ) {
    }

    /**
     * @template TResult of ResultInterface|int|string
     *
     * @param Closure(): TResult $query
     *
     * @return TResult
     */
    public function measure(string $sql, Closure $query): mixed
    {
        $startTime = microtime(true);
        $result = $query();
        $duration = microtime(true) - $startTime;

        $labels = $this->labeller->label($sql);
        $this->collector->incQueryExecute($this->connectionName, $labels->type, $labels->table);
        $this->collector->setQueryExecuteDuration($this->connectionName, $labels->type, $labels->table, $duration);

        return $result;
    }
}
