<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Cache;

/**
 * Shared by a counting decorator and the CountingCallbackWrapper of its pool: a get() is a miss when the count moved
 * while it ran.
 *
 * @internal
 */
final class ComputationCounter
{
    /** @var non-negative-int */
    private int $computations = 0;

    public function record(): void
    {
        $this->computations++;
    }

    /**
     * @return non-negative-int
     */
    public function count(): int
    {
        return $this->computations;
    }
}
