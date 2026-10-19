<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Application\Collector;

use Msstc4Symfony\MetricsBundle\Application\Collector\MongoDbCollector;
use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MongoDbCollector::class)]
final class MongoDbCollectorTest extends RecorderTestCase
{
    public function testIncCommandSuccess(): void
    {
        $this->build()->incCommandSuccess('find', 'mongo-1:27017');

        self::assertSame([['increment', BuiltInMetric::MONGODB_COMMAND_SUCCESS, null, ['find', 'mongo-1:27017']]], $this->recorder->calls);
    }

    public function testIncCommandFailed(): void
    {
        $this->build()->incCommandFailed('insert', 'mongo-1:27017');

        self::assertSame([['increment', BuiltInMetric::MONGODB_COMMAND_FAILED, null, ['insert', 'mongo-1:27017']]], $this->recorder->calls);
    }

    public function testSetCommandDurationObserved(): void
    {
        $this->build()->setCommandDuration('find', 'mongo-1:27017', 0.03);

        self::assertSame([['observe', BuiltInMetric::MONGODB_COMMAND_DURATION_HISTOGRAM_SECONDS, 0.03, ['find', 'mongo-1:27017']]], $this->recorder->calls);
    }

    private function build(): MongoDbCollector
    {
        return new MongoDbCollector($this->recorder);
    }
}
