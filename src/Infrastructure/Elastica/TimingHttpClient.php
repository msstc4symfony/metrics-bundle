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
    ) {
    }

    #[Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $method = $request->getMethod();
        $path = ltrim($request->getUri()->getPath(), '/');
        $start = hrtime(true);

        try {
            $response = $this->inner->sendRequest($request);
        } catch (Throwable $exception) {
            $this->collector->incRequestFailed($method, $path);

            throw $exception;
        }

        if ($response->getStatusCode() >= 400 && $this->carriesElasticsearchError($response)) {
            $this->collector->incRequestFailed($method, $path);

            return $response;
        }

        $this->collector->incRequestSuccess($method, $path);
        $this->collector->setRequestDuration($method, $path, (hrtime(true) - $start) / 1e9);

        return $response;
    }

    /**
     * Mirrors the Elastica 7 HTTP transport: only a body with a top-level "error" key (or a non-JSON-object body) is a failure.
     */
    private function carriesElasticsearchError(ResponseInterface $response): bool
    {
        $body = $response->getBody();
        if (!$body->isSeekable()) {
            return true;
        }

        $body->rewind();
        $contents = $body->getContents();
        $body->rewind();

        if ($contents === '') {
            return false;
        }

        $decoded = json_decode($contents);

        return !$decoded instanceof stdClass || property_exists($decoded, 'error');
    }
}
