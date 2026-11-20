<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Support;

use Msstc4Symfony\MetricsBundle\Application\Port\MessengerMeasurement;
use Msstc4Symfony\MetricsBundle\Application\Port\MessengerMeasurementObserverInterface;
use Override;

final class RecordingMessengerMeasurementObserver implements MessengerMeasurementObserverInterface
{
    /** @var list<MessengerMeasurement> */
    public array $measurements = [];

    #[Override]
    public function onMessageMeasured(MessengerMeasurement $measurement): void
    {
        $this->measurements[] = $measurement;
    }
}
