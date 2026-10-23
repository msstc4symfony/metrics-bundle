<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\HttpClient;

use Generator;
use Msstc4Symfony\MetricsBundle\Application\Collector\ExternalConnectionCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\OutgoingHttp\OutgoingRequestLabels;
use Override;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use SplObjectStorage;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Component\HttpClient\Response\ResponseStream;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Metrics are recorded when the caller reaches the status or the end of the body, never in
 * request(): reading the status there would serialize concurrent requests and disable the
 * destructor-time status check of unconsumed responses.
 *
 * @internal
 */
final class HttpClientDecorator implements HttpClientInterface, ResetInterface, LoggerAwareInterface
{
    use DecoratorTrait;

    public function __construct(
        HttpClientInterface $inner,
        private readonly ExternalConnectionCollector $collector,
        private readonly OutgoingRequestLabels $labels,
    ) {
        $this->client = $inner;
    }

    /**
     * @param array<mixed> $options
     */
    #[Override]
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $response = $this->client->request($method, $url, $options);

        // The inner response knows its resolved URL immediately, including a base_uri set
        // through withOptions() that this decorator never sees in $options.
        $resolvedUrl = $response->getInfo('url');
        [$host, $path] = $this->labels->of(is_string($resolvedUrl) && $resolvedUrl !== '' ? $resolvedUrl : $url);

        $this->collector->incHTTPConnectionRequest($method, $host, $path);

        return new MonitoredResponse(
            $response,
            $this,
            fn (int $status) => $this->collector->incHTTPConnectionResponse($method, $host, $path, $status),
            fn (float $seconds) => $this->collector->setHTTPConnectionDuration($method, $host, $path, $seconds),
        );
    }

    #[Override]
    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        if ($responses instanceof ResponseInterface) {
            $responses = [$responses];
        }

        /** @var SplObjectStorage<ResponseInterface, MonitoredResponse> $monitored */
        $monitored = new SplObjectStorage();
        $inner = [];

        foreach ($responses as $response) {
            if ($response instanceof MonitoredResponse) {
                $monitored[$response->inner()] = $response;
                $response = $response->inner();
            }

            $inner[] = $response;
        }

        return new ResponseStream($this->observe($this->client->stream($inner, $timeout), $monitored));
    }

    #[Override]
    public function setLogger(LoggerInterface $logger): void
    {
        if ($this->client instanceof LoggerAwareInterface) {
            $this->client->setLogger($logger);
        }
    }

    /**
     * @param SplObjectStorage<ResponseInterface, MonitoredResponse> $monitored
     *
     * @return Generator<ResponseInterface, ChunkInterface, mixed, void>
     */
    private function observe(ResponseStreamInterface $stream, SplObjectStorage $monitored): Generator
    {
        foreach ($stream as $response => $chunk) {
            $wrapper = $monitored->offsetExists($response) ? $monitored[$response] : null;

            // Error and timeout chunks throw from isFirst()/isLast(); they reach the caller untouched.
            if ($wrapper !== null && $chunk->getError() === null) {
                if ($chunk->isFirst()) {
                    $wrapper->recordStatus();
                }

                if ($chunk->isLast()) {
                    $wrapper->recordCompletion();
                }
            }

            yield $wrapper ?? $response => $chunk;
        }
    }
}
