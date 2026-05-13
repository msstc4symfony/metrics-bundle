<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Test\Unit\Infrastructure\Collector;

use MaxShamaev\MetricsBundle\Infrastructure\Collector\MongoDbCollector;
use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use MaxShamaev\MetricsBundle\Infrastructure\Repository\MetricRepository;

final class MongoDbCollectorTest extends CollectorTestCase
{
    public function testIncCommandSuccess(): void
    {
        $this->build()->incCommandSuccess('find', 'mongo-1:27017');

        self::assertSame(
            [['app', 'cmp', 'find', 'mongo-1:27017']],
            $this->labelValuesFor('symfony_' . MetricLabelEnum::MONGODB_COMMAND_SUCCESS->value),
        );
    }

    public function testIncCommandFailed(): void
    {
        $this->build()->incCommandFailed('insert', 'mongo-1:27017');

        self::assertSame(
            [['app', 'cmp', 'insert', 'mongo-1:27017']],
            $this->labelValuesFor('symfony_' . MetricLabelEnum::MONGODB_COMMAND_FAILED->value),
        );
    }

    public function testSetCommandDurationObserved(): void
    {
        $this->build()->setCommandDuration('find', 'mongo-1:27017', 0.03);

        self::assertTrue($this->familyExists('symfony_' . MetricLabelEnum::MONGODB_COMMAND_DURATION_HISTOGRAM_SECONDS->value));
    }

    private function build(): MongoDbCollector
    {
        return new MongoDbCollector($this->registry, new MetricRepository([]), 'app', 'cmp');
    }
}
