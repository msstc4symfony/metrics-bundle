<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Presentation\EventListener;

/**
 * Process-local state of MessengerQueueDepthListener, which is readonly.
 *
 * @internal
 */
final class QueueDepthSamplingState
{
    private float $notBefore = 0.0;

    /** @var list<string> */
    private array $reportedUncountable = [];

    public function isThrottled(float $now): bool
    {
        return $now < $this->notBefore;
    }

    /**
     * @param float $end Unix time before which the shared throttle is not asked again
     */
    public function throttleUntil(float $end): void
    {
        $this->notBefore = $end;
    }

    /**
     * @return bool true the first time only
     */
    public function markUncountableOnce(string $transport): bool
    {
        if (\in_array($transport, $this->reportedUncountable, true)) {
            return false;
        }

        $this->reportedUncountable[] = $transport;

        return true;
    }
}
