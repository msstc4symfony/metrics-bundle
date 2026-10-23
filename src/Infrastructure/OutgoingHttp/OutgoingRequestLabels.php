<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\OutgoingHttp;

use Msstc4Symfony\MetricsBundle\Infrastructure\HttpClient\URLAssembler\AssemblerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The host and path labels of the http_connection_* series, shared by the symfony/http-client and Guzzle
 * measurement points so that both write the same series for the same URL.
 *
 * @internal
 */
final readonly class OutgoingRequestLabels
{
    /**
     * @param iterable<array-key, AssemblerInterface> $urlAssemblers
     */
    public function __construct(
        #[AutowireIterator(AssemblerInterface::TAG)]
        private iterable $urlAssemblers,
        #[Autowire(param: 'msstc4symfony_metrics.http_client.sanitize_path')]
        private bool $sanitizePath,
    ) {
    }

    /**
     * @return array{string, string} host and path
     */
    public function of(string $url): array
    {
        foreach ($this->urlAssemblers as $urlAssembler) {
            $result = $urlAssembler->assemble($url);
            if ($result !== null) {
                return $result;
            }
        }

        $parts = parse_url($url);
        $host = \is_array($parts) && isset($parts['host']) ? $parts['host'] : '';
        $path = \is_array($parts) && isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';

        return [$host, $this->sanitizePath ? PathSanitizer::sanitize($path) : $path];
    }
}
