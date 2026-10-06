<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Elastica;

use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\ElasticaCollector;
use Override;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use stdClass;
use Throwable;

final readonly class TimingHttpClient implements ClientInterface
{
    public function __construct(
        private ClientInterface $inner,
        private ElasticaCollector $collector,
        private bool $sanitizePath = true,
    ) {
    }

    #[Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $method = $request->getMethod();
        $path = ltrim($request->getUri()->getPath(), '/');
        if ($this->sanitizePath) {
            $path = ElasticaPathSanitizer::sanitize($path);
        }
        $start = hrtime(true);

        try {
            $response = $this->inner->sendRequest($request);
        } catch (Throwable $exception) {
            $this->collector->incRequestFailed($method, $path);

            throw $exception;
        }

        if ($response->getStatusCode() >= 400 && $this->isFailedErrorResponse($response)) {
            $this->collector->incRequestFailed($method, $path);

            return $response;
        }

        $this->collector->incRequestSuccess($method, $path);
        $this->collector->setRequestDuration($method, $path, (hrtime(true) - $start) / 1e9);

        return $response;
    }

    /**
     * Like the Elastica 7 HTTP transport, a JSON object without a top-level "error" key is not a failure (404 "found": false).
     * Deliberate difference: a non-JSON body (proxy HTML, plain text) is a failure here, while Elastica 7 wraps it as
     * {"message": …} and counts it as a success. An unreadable body counts as failed instead of failing a completed request.
     */
    private function isFailedErrorResponse(ResponseInterface $response): bool
    {
        $body = $response->getBody();
        if (!$body->isSeekable()) {
            return true;
        }

        try {
            $body->rewind();
            $contents = $body->getContents();
            $body->rewind();
        } catch (Throwable) {
            try {
                $body->rewind();
            } catch (Throwable) {
            }

            return true;
        }

        if ($contents === '') {
            return false;
        }

        $decoded = json_decode($contents);

        return !$decoded instanceof stdClass || property_exists($decoded, 'error');
    }
}
