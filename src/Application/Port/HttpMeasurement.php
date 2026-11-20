<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Application\Port;

use InvalidArgumentException;
use Throwable;

/**
 * New fields are added only as optional trailing constructor parameters.
 */
final readonly class HttpMeasurement
{
    /**
     * @param string $method Upper-case HTTP method as written to the metrics: GET, POST, PUT, PATCH, DELETE, HEAD or OTHER
     * @param non-empty-string|null $route Route name; null where the metrics write "unknown" (the request was not routed)
     * @param float $durationSeconds Same value that was written to the duration metrics
     * @param Throwable|null $exception The throwable that reached kernel.exception for the main request. A later
     *                                  listener may have turned it into a 3xx/401/403 response: classify by $status.
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

        $this->assertRoute($route);

        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException(\sprintf('HTTP status must be in 100..599, %d given.', $status));
        }

        if ($durationSeconds < 0.0) {
            throw new InvalidArgumentException(\sprintf('Duration must not be negative, %F given.', $durationSeconds));
        }
    }

    // Kept apart from the constructor: its narrowed @param would let Rector drop the check as dead code.
    private function assertRoute(?string $route): void
    {
        if ($route === '') {
            throw new InvalidArgumentException('HTTP route must not be empty; use null for an unrouted request.');
        }
    }
}
