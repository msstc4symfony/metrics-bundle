<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Support;

use Msstc4Symfony\MetricsBundle\Application\Port\MessengerMeasurement;
use Msstc4Symfony\MetricsBundle\Application\Port\MessengerMeasurementObserverInterface;
use Override;
use RuntimeException;

final class ThrowingMessengerMeasurementObserver implements MessengerMeasurementObserverInterface
{
    #[Override]
    public function onMessageMeasured(MessengerMeasurement $measurement): void
    {
        throw new RuntimeException('observer failed');
    }
}
