<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Support;

use Msstc4Symfony\MetricsBundle\Application\Port\HttpMeasurement;
use Msstc4Symfony\MetricsBundle\Application\Port\HttpMeasurementObserverInterface;
use Override;

final class RecordingHttpMeasurementObserver implements HttpMeasurementObserverInterface
{
    /** @var list<HttpMeasurement> */
    public array $measurements = [];

    #[Override]
    public function onHttpMeasured(HttpMeasurement $measurement): void
    {
        $this->measurements[] = $measurement;
    }
}
