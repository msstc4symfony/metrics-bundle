<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Collector;

use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;

final class MongoDbCollector extends AbstractCollector
{
    public function incCommandSuccess(string $command, string $host): void
    {
        $this->incCounter(MetricLabelEnum::MONGODB_COMMAND_SUCCESS, [$command, $host]);
    }

    public function incCommandFailed(string $command, string $host): void
    {
        $this->incCounter(MetricLabelEnum::MONGODB_COMMAND_FAILED, [$command, $host]);
    }

    public function setCommandDuration(string $command, string $host, float $duration): void
    {
        $this->observeHistogram(MetricLabelEnum::MONGODB_COMMAND_DURATION_HISTOGRAM_SECONDS, $duration, [$command, $host]);
    }
}
