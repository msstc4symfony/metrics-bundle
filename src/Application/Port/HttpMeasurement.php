<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Application\Port;

use InvalidArgumentException;
use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Throwable;

/**
 * New fields are added only as optional trailing constructor parameters.
 *
 * @phpstan-import-type HttpMethod from BuiltInMetric
 */
final readonly class HttpMeasurement
{
    /**
     * @param HttpMethod $method HTTP method as written to the metrics (BuiltInMetric::HTTP_METHODS); any other method is OTHER
     * @param non-empty-string|null $route Route name; null where the metrics write "unknown" (the request was not routed)
     * @param int<100, 599> $status
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
        $this->assertMethod($method);
        $this->assertRoute($route);
        $this->assertStatus($status);

        if ($durationSeconds < 0.0) {
            throw new InvalidArgumentException(\sprintf('Duration must not be negative, %F given.', $durationSeconds));
        }
    }

    /**
     * @phpstan-assert-if-true int<100, 599> $status
     */
    public static function isRecordableStatus(int $status): bool
    {
        return $status >= 100 && $status <= 599;
    }

    // The checks below are kept apart from the constructor: its narrowed @param would let Rector drop them as dead code.
    private function assertMethod(string $method): void
    {
        if (!\in_array($method, BuiltInMetric::HTTP_METHODS, true)) {
            throw new InvalidArgumentException(\sprintf('HTTP method must be one of %s, "%s" given.', implode(', ', BuiltInMetric::HTTP_METHODS), $method));
        }
    }

    private function assertRoute(?string $route): void
    {
        if ($route === '') {
            throw new InvalidArgumentException('HTTP route must not be empty; use null for an unrouted request.');
        }
    }

    private function assertStatus(int $status): void
    {
        if (!self::isRecordableStatus($status)) {
            throw new InvalidArgumentException(\sprintf('HTTP status must be in 100..599, %d given.', $status));
        }
    }
}
