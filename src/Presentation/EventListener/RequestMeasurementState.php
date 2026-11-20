<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Presentation\EventListener;

use Throwable;

/**
 * @internal
 */
final class RequestMeasurementState
{
    private bool $counted = false;

    private ?Throwable $exception = null;

    /**
     * @param float|int $startedAt hrtime() nanoseconds
     */
    public function __construct(
        public readonly float|int $startedAt,
    ) {
    }

    public function markCounted(): void
    {
        $this->counted = true;
    }

    public function isCounted(): bool
    {
        return $this->counted;
    }

    public function recordException(Throwable $exception): void
    {
        $this->exception = $exception;
    }

    public function exception(): ?Throwable
    {
        return $this->exception;
    }
}
