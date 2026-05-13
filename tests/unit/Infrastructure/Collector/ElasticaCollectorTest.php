<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Test\Unit\Infrastructure\Collector;

use MaxShamaev\MetricsBundle\Infrastructure\Collector\ElasticaCollector;
use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use MaxShamaev\MetricsBundle\Infrastructure\Repository\MetricRepository;

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
