<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\HttpClient;

use Closure;
use Override;
use Symfony\Component\HttpClient\Response\StreamableInterface;
use Symfony\Component\HttpClient\Response\StreamWrapper;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
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
    // With $throw the inner calls raise HttpExceptionInterface on 4xx/5xx — exactly the
    // statuses alerting relies on — so those are recorded before rethrowing. Transport
    // errors carry no response and are not recorded.
    private bool $statusRecorded = false;

    private bool $completed = false;

    /**
     * @param Closure(int): void $onStatus receives the final HTTP status
     * @param Closure(float): void $onComplete receives the total transfer time in seconds
     */
    public function __construct(
        private readonly ResponseInterface $inner,
        private readonly HttpClientInterface $client,
        private readonly Closure $onStatus,
        private readonly Closure $onComplete,
    ) {
    }

    public function __destruct()
    {
        // The inner destructor waits for the headers of an unread response (and throws on
        // 4xx/5xx), so run it here first: fire-and-forget calls still get their status.
        try {
            if (method_exists($this->inner, '__destruct')) {
                $this->inner->__destruct();
            }
        } catch (HttpExceptionInterface $e) {
            $this->recordStatus();

            throw $e;
        }

        $this->recordStatus();
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
        try {
            $headers = $this->inner->getHeaders($throw);
        } catch (HttpExceptionInterface $e) {
            $this->recordStatus();

            throw $e;
        }

        $this->recordStatus();

        return $headers;
    }

    #[Override]
    public function getContent(bool $throw = true): string
    {
        try {
            $content = $this->inner->getContent($throw);
        } catch (HttpExceptionInterface $e) {
            $this->recordStatus();

            throw $e;
        }

        $this->recordCompletion();

        return $content;
    }

    /**
     * @return array<mixed>
     */
    #[Override]
    public function toArray(bool $throw = true): array
    {
        try {
            $data = $this->inner->toArray($throw);
        } catch (HttpExceptionInterface $e) {
            $this->recordStatus();

            throw $e;
        }

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

        // Reading through the decorating client routes the body via its stream(), which records
        // completion; the inner toStream() would bypass it (Psr18Client/HttplugClient use this).
        return StreamWrapper::createResource($this, $this->client);
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
