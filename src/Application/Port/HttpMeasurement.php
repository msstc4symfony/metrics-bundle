<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Application\Port;

use InvalidArgumentException;
use Throwable;

final readonly class HttpMeasurement
{
    /**
     * @param string $method Upper-case HTTP method
     * @param float $durationSeconds Same value that was written to the duration metrics
     * @param Throwable|null $exception Uncaught exception of the main request, if any
     */
    public function __construct(
        public string $method,
        public ?string $route,
        public int $status,
        public float $durationSeconds,
        public ?Throwable $exception,
    ) {
        if ($method === '' || $method !== strtoupper($method)) {
            throw new InvalidArgumentException(\sprintf('HTTP method must be a non-empty upper-case string, "%s" given.', $method));
        }

        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException(\sprintf('HTTP status must be in 100..599, %d given.', $status));
        }

        if ($durationSeconds < 0.0) {
            throw new InvalidArgumentException(\sprintf('Duration must not be negative, %F given.', $durationSeconds));
        }
    }
}
