<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Application\Port;

/**
 * Notified once per worker outcome (handled, retried, failed), after the bundle wrote its own metrics.
 * Autoconfigured: implementing services are tagged automatically.
 */
interface MessengerMeasurementObserverInterface
{
    public const TAG = 'msstc4symfony_metrics.messenger_measurement_observer';

    public function onMessageMeasured(MessengerMeasurement $measurement): void;
}
