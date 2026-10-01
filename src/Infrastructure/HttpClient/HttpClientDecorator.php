<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\HttpClient;

use Generator;
use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\ExternalConnectionCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\HttpClient\URLAssembler\AssemblerInterface;
use Override;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\AsyncDecoratorTrait;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Response\AsyncResponse;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Records metrics while the response streams instead of reading it in request():
 * reading the status there would serialize concurrent requests and disable the
 * destructor-time status check of unconsumed responses.
 */
final class HttpClientDecorator implements HttpClientInterface, ResetInterface, LoggerAwareInterface
{
    use AsyncDecoratorTrait;

    /**
     * @param iterable<AssemblerInterface> $urlAssemblers
     */
    public function __construct(
        HttpClientInterface $inner,
        private readonly ExternalConnectionCollector $collector,
        private readonly iterable $urlAssemblers,
        #[Autowire(param: 'metrics_bundle.httpClientSanitizePath')]
        private readonly bool $sanitizePath = true,
    ) {
        $this->client = $inner;
    }

    /**
     * @param array<mixed> $options
     */
    #[Override]
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $baseUri = isset($options['base_uri']) && is_string($options['base_uri']) ? $options['base_uri'] : null;
        [$host, $path] = $this->processUrl($url, $baseUri);

        $this->collector->incHTTPConnectionRequest($method, $host, $path);

        $passthru = function (ChunkInterface $chunk, AsyncContext $context) use ($method, $host, $path): Generator {
            // Error chunks throw from isFirst()/isLast(); the error itself reaches the caller unchanged.
            if ($chunk->getError() === null) {
                if ($chunk->isFirst()) {
                    $this->collector->incHTTPConnectionResponse($method, $host, $path, $context->getStatusCode());
                }

                if ($chunk->isLast()) {
                    $totalTime = $context->getInfo('total_time');
                    if (is_float($totalTime) || is_int($totalTime)) {
                        $this->collector->setHTTPConnectionDuration($method, $host, $path, (float) $totalTime);
                    }
                }
            }

            yield $chunk;
        };

        return new AsyncResponse($this->client, $method, $url, $options, $passthru);
    }

    #[Override]
    public function setLogger(LoggerInterface $logger): void
    {
        if ($this->client instanceof LoggerAwareInterface) {
            $this->client->setLogger($logger);
        }
    }

    /**
     * @return array{string, string}
     */
    private function processUrl(string $url, ?string $baseUri): array
    {
        $url = $this->prepareUrl($url, $baseUri);

        foreach ($this->urlAssemblers as $urlAssembler) {
            $result = $urlAssembler->assemble($url);
            if ($result !== null) {
                return $result;
            }
        }

        $parts = parse_url($url);
        $host = is_array($parts) && isset($parts['host']) ? $parts['host'] : '';
        $path = is_array($parts) && isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';

        return [$host, $this->sanitizePath ? PathSanitizer::sanitize($path) : $path];
    }

    private function prepareUrl(string $url, ?string $baseUri): string
    {
        if ($baseUri === null || is_string(parse_url($url, PHP_URL_HOST))) {
            return $url;
        }

        return rtrim($baseUri, '/') . '/' . ltrim($url, '/');
    }
}
