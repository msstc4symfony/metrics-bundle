<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics;

use Closure;
use Doctrine\DBAL\Driver\Result as ResultInterface;
use Msstc4Symfony\MetricsBundle\Application\Collector\DoctrineConnectionCollector;
use Msstc4Symfony\MetricsBundle\Application\Collector\DoctrineQueryType;
use Msstc4Symfony\SqlLabeller\Domain\Helper\SqlLabelling;

/** @internal Shared by the connection and statement middlewares. */
final readonly class QueryMeter
{
    public function __construct(
        private DoctrineConnectionCollector $collector,
        private string $connectionName,
        private SqlLabelling $labeller,
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
        // The values are the same; a type a later labeller adds must not fail the query it measured.
        $type = DoctrineQueryType::tryFrom($labels->type->value) ?? DoctrineQueryType::OTHER;
        $this->collector->incQueryExecute($this->connectionName, $type, $labels->table);
        $this->collector->setQueryExecuteDuration($this->connectionName, $type, $labels->table, $duration);

        return $result;
    }
}
