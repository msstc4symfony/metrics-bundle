<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Application\Port;

/**
 * Notified once per recorded main HTTP request, after the bundle wrote its own metrics.
 * Autoconfigured: implementing services are tagged automatically.
 */
interface HttpMeasurementObserverInterface
{
    public const TAG = 'msstc4symfony_metrics.http_measurement_observer';

    public function onHttpMeasured(HttpMeasurement $measurement): void;
}
