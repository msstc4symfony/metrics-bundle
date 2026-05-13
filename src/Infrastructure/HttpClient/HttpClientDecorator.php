<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\HttpClient;

use MaxShamaev\MetricsBundle\Infrastructure\Collector\ExternalConnectionCollector;
use MaxShamaev\MetricsBundle\Infrastructure\HttpClient\URLAssembler\AssemblerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class HttpClientDecorator implements HttpClientInterface
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private const LONG_HEX_PATTERN = '/^[0-9a-f]{24,}$/i';

    private const DIGITS_PATTERN = '/^\d+$/';

    /**
     * @param iterable<AssemblerInterface> $urlAssemblers
     */
    public function __construct(
        private HttpClientInterface $inner,
        private readonly ExternalConnectionCollector $collector,
        private readonly iterable $urlAssemblers,
        private readonly ?string $baseUri = null,
        #[Autowire(param: 'metrics_bundle.httpClientSanitizePath')]
        private readonly bool $sanitizePath = true,
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
            $this->collector->setHTTPConnectionDuration(
                $method,
                $metricHost,
                $metricPath,
                microtime(true) - $startTime,
            );
        }

        return $response;
    }

    public function stream(
        ResponseInterface|iterable $responses,
        ?float $timeout = null,
    ): ResponseStreamInterface {
        return $this->inner->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->inner = $this->inner->withOptions($options);

        return $clone;
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

        $parts = parse_url($url);
        $host = is_array($parts) && isset($parts['host']) ? $parts['host'] : '';
        $path = is_array($parts) && isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';

        return [$host, $this->sanitizePath ? $this->sanitize($path) : $path];
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

    private function sanitize(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }

        $segments = explode('/', $path);
        foreach ($segments as $i => $segment) {
            if ($segment === '') {
                continue;
            }
            if (preg_match(self::UUID_PATTERN, $segment) === 1) {
                $segments[$i] = ':uuid';
                continue;
            }
            if (preg_match(self::DIGITS_PATTERN, $segment) === 1) {
                $segments[$i] = ':id';
                continue;
            }
            if (preg_match(self::LONG_HEX_PATTERN, $segment) === 1) {
                $segments[$i] = ':hash';
            }
        }

        return implode('/', $segments);
    }
}
