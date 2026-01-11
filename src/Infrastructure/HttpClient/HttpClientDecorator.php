<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\HttpClient;

use MaxShamaev\MetricsBundle\Infrastructure\Collector\ExternalConnectionCollector;
use MaxShamaev\MetricsBundle\Infrastructure\HttpClient\URLAssembler\AssemblerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

class HttpClientDecorator implements HttpClientInterface
{
    /**
     * @param iterable<AssemblerInterface> $urlAssemblers
     */
    public function __construct(
        private HttpClientInterface $inner,
        private readonly ExternalConnectionCollector $collector,
        private readonly iterable $urlAssemblers,
        private readonly ?string $baseUri = null,
    ) {
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        [$metricHost, $metricPath] = $this->processUrl($url, $options);

        $this->collector->incHTTPConnectionRequest($method, $metricHost, $metricPath);

        $response = $this->inner->request($method, $url, $options);

        $this->collector->incHTTPConnectionResponse($method, $metricHost, $metricPath, $response->getStatusCode());

        $startTime = (float) $response->getInfo()['start_time'];
        if ($startTime > 0) {
            $this->collector->setHTTPConnectionDuration($method, $metricHost, $metricPath, microtime(true) - $startTime);
        }

        return $response;
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        $this->inner = $this->inner->withOptions($options);

        return $this;
    }

    /**
     * @param array{base_uri?: ?string} $options
     *
     * @return array{string, string}
     */
    private function processUrl(string $url, array $options): array
    {
        $url = $this->prepareUrl($url, $options);

        foreach ($this->urlAssemblers as $urlAssembler) {
            $result = $urlAssembler->assemble($url);
            if ($result !== null) {
                return $result;
            }
        }

        return [(string) parse_url($url, PHP_URL_HOST), parse_url($url, PHP_URL_PATH) ?: '/'];
    }

    /**
     * @param array{base_uri?: ?string} $options
     */
    private function prepareUrl(string $url, array $options): string
    {
        if (is_string(parse_url($url, PHP_URL_HOST))) {
            return $url;
        }

        $baseUri = $options['base_uri'] ?? $this->baseUri;
        if ($baseUri === null) {
            return $url;
        }

        return rtrim($baseUri, '/') . '/' . ltrim($url, '/');
    }
}
