<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Application\Collector;

use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Application\Port\MetricRecorderInterface;

/**
 * @internal
 */
final readonly class MongoDbCollector
{
    public function __construct(
        private MetricRecorderInterface $recorder,
    ) {
    }

    public function incCommandSuccess(string $command, string $host): void
    {
        $this->recorder->increment(BuiltInMetric::MONGODB_COMMAND_SUCCESS, [$command, $host]);
    }

    public function incCommandFailed(string $command, string $host): void
    {
        $this->recorder->increment(BuiltInMetric::MONGODB_COMMAND_FAILED, [$command, $host]);
    }

    public function setCommandDuration(string $command, string $host, float $duration): void
    {
        $this->recorder->observe(BuiltInMetric::MONGODB_COMMAND_DURATION_HISTOGRAM_SECONDS, $duration, [$command, $host]);
    }
}
