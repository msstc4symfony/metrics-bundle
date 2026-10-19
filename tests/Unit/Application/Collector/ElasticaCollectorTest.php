<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Application\Collector;

use Msstc4Symfony\MetricsBundle\Application\Collector\ElasticaCollector;
use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ElasticaCollector::class)]
final class ElasticaCollectorTest extends RecorderTestCase
{
    public function testIncRequestSuccess(): void
    {
        $this->build()->incRequestSuccess('POST', '/index/_search');

        self::assertSame([['increment', BuiltInMetric::ELASTICA_REQUEST_SUCCESS, null, ['POST', '/index/_search']]], $this->recorder->calls);
    }

    public function testIncRequestFailed(): void
    {
        $this->build()->incRequestFailed('POST', '/index/_search');

        self::assertSame([['increment', BuiltInMetric::ELASTICA_REQUEST_FAILED, null, ['POST', '/index/_search']]], $this->recorder->calls);
    }

    public function testSetRequestDurationObserved(): void
    {
        $this->build()->setRequestDuration('POST', '/index/_search', 0.05);

        self::assertSame([['observe', BuiltInMetric::ELASTICA_REQUEST_DURATION_HISTOGRAM_SECONDS, 0.05, ['POST', '/index/_search']]], $this->recorder->calls);
    }

    private function build(): ElasticaCollector
    {
        return new ElasticaCollector($this->recorder);
    }
}
