<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics;

use Msstc4Symfony\MetricsBundle\Application\Collector\DoctrineQueryType;

/** @internal */
final readonly class QueryLabels
{
    public function __construct(
        public DoctrineQueryType $type,
        public ?string $table,
    ) {
    }
}
