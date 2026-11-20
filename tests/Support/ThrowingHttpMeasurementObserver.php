<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Support;

use Msstc4Symfony\MetricsBundle\Application\Port\HttpMeasurement;
use Msstc4Symfony\MetricsBundle\Application\Port\HttpMeasurementObserverInterface;
use Override;
use RuntimeException;

final class ThrowingHttpMeasurementObserver implements HttpMeasurementObserverInterface
{
    #[Override]
    public function onHttpMeasured(HttpMeasurement $measurement): void
    {
        throw new RuntimeException('observer failed');
    }
}
