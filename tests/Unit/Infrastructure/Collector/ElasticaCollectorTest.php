<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Infrastructure\Collector;

use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\ElasticaCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepository;

final class ElasticaCollectorTest extends CollectorTestCase
{
    public function testIncRequestSuccess(): void
    {
        $this->build()->incRequestSuccess('POST', '/index/_search');

        self::assertSame(
            [['app', 'cmp', 'POST', '/index/_search']],
            $this->labelValuesFor('symfony_' . MetricLabelEnum::ELASTICA_REQUEST_SUCCESS->value),
        );
    }

    public function testIncRequestFailed(): void
    {
        $this->build()->incRequestFailed('POST', '/index/_search');

        self::assertSame(
            [['app', 'cmp', 'POST', '/index/_search']],
            $this->labelValuesFor('symfony_' . MetricLabelEnum::ELASTICA_REQUEST_FAILED->value),
        );
    }

    public function testSetRequestDurationObserved(): void
    {
        $this->build()->setRequestDuration('POST', '/index/_search', 0.05);

        self::assertTrue($this->familyExists('symfony_' . MetricLabelEnum::ELASTICA_REQUEST_DURATION_HISTOGRAM_SECONDS->value));
    }

    private function build(): ElasticaCollector
    {
        return new ElasticaCollector($this->registry, new MetricRepository([]), 'app', 'cmp');
    }
}
