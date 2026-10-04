<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Infrastructure\Collector;

use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\MongoDbCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepository;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;

final class MongoDbCollectorTest extends CollectorTestCase
{
    public function testIncCommandSuccess(): void
    {
        $this->build()->incCommandSuccess('find', 'mongo-1:27017');

        self::assertSame(
            [['app', 'cmp', 'find', 'mongo-1:27017']],
            RegistrySamples::labels($this->registry, MetricLabelEnum::MONGODB_COMMAND_SUCCESS),
        );
    }

    public function testIncCommandFailed(): void
    {
        $this->build()->incCommandFailed('insert', 'mongo-1:27017');

        self::assertSame(
            [['app', 'cmp', 'insert', 'mongo-1:27017']],
            RegistrySamples::labels($this->registry, MetricLabelEnum::MONGODB_COMMAND_FAILED),
        );
    }

    public function testSetCommandDurationObserved(): void
    {
        $this->build()->setCommandDuration('find', 'mongo-1:27017', 0.03);

        self::assertTrue(RegistrySamples::exists($this->registry, MetricLabelEnum::MONGODB_COMMAND_DURATION_HISTOGRAM_SECONDS));
    }

    private function build(): MongoDbCollector
    {
        return new MongoDbCollector($this->registry, new MetricRepository([]), 'app', 'cmp');
    }
}
