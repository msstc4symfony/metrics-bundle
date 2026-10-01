<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\HttpClient;

use Closure;
use Override;
use Symfony\Component\HttpClient\Response\StreamableInterface;
use Symfony\Component\HttpClient\Response\StreamWrapper;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Transparent wrapper in the spirit of TraceableResponse: every call is forwarded as-is, so
 * exceptions keep their original class (TimeoutException stays TimeoutException), and the
 * metrics callbacks fire when the caller itself reaches the status or the end of the body.
 *
 * @internal created by HttpClientDecorator only
 */
final class MonitoredResponse implements ResponseInterface, StreamableInterface
{
    private bool $statusRecorded = false;

    private bool $completed = false;

    /**
     * @param Closure(int): void $onStatus receives the final HTTP status
     * @param Closure(float): void $onComplete receives the total transfer time in seconds
     */
    public function __construct(
        private readonly ResponseInterface $inner,
        private readonly Closure $onStatus,
        private readonly Closure $onComplete,
    ) {
    }

    public function inner(): ResponseInterface
    {
        return $this->inner;
    }

    #[Override]
    public function getStatusCode(): int
    {
        $status = $this->inner->getStatusCode();
        $this->recordStatus();

        return $status;
    }

    /**
     * @return array<string, list<string>>
     */
    #[Override]
    public function getHeaders(bool $throw = true): array
    {
        $headers = $this->inner->getHeaders($throw);
        $this->recordStatus();

        return $headers;
    }

    #[Override]
    public function getContent(bool $throw = true): string
    {
        $content = $this->inner->getContent($throw);
        $this->recordCompletion();

        return $content;
    }

    /**
     * @return array<mixed>
     */
    #[Override]
    public function toArray(bool $throw = true): array
    {
        $data = $this->inner->toArray($throw);
        $this->recordCompletion();

        return $data;
    }

    #[Override]
    public function cancel(): void
    {
        // A cancelled transfer has no meaningful duration.
        $this->completed = true;
        $this->inner->cancel();
    }

    #[Override]
    public function getInfo(?string $type = null): mixed
    {
        return $this->inner->getInfo($type);
    }

    /**
     * @return resource
     */
    #[Override]
    public function toStream(bool $throw = true)
    {
        if ($throw) {
            $this->getHeaders();
        }

        return $this->inner instanceof StreamableInterface
            ? $this->inner->toStream(false)
            : StreamWrapper::createResource($this->inner);
    }

    /**
     * Called by HttpClientDecorator::stream() once the first non-informational chunk arrived.
     */
    public function recordStatus(): void
    {
        if ($this->statusRecorded) {
            return;
        }

        $status = $this->inner->getInfo('http_code');
        if (!is_int($status) || $status === 0) {
            return;
        }

        $this->statusRecorded = true;
        ($this->onStatus)($status);
    }

    /**
     * Called by HttpClientDecorator::stream() on the last chunk of a fully received body.
     */
    public function recordCompletion(): void
    {
        $this->recordStatus();

        if ($this->completed || !$this->statusRecorded) {
            return;
        }

        $this->completed = true;

        $totalTime = $this->inner->getInfo('total_time');
        if (is_float($totalTime) || is_int($totalTime)) {
            ($this->onComplete)((float) $totalTime);
        }
    }
}
