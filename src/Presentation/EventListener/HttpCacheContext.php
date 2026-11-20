<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Presentation\EventListener;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpCache\HttpCache;

/**
 * What framework.http_cache changes about a main request the kernel handles: HttpCache forwards a clone of the client
 * request (a HEAD miss as GET) and every ESI fragment miss, all as main requests.
 *
 * @internal
 */
final readonly class HttpCacheContext
{
    /**
     * @param HttpCache|null $httpCache The http_cache service, null unless framework.http_cache is enabled
     */
    public function __construct(
        private ?HttpCache $httpCache,
    ) {
    }

    /**
     * HttpCache clones of the client request keep its REQUEST_URI; an ESI fragment request has its own.
     */
    public function isForwardedFragment(Request $request): bool
    {
        $client = $this->clientRequestBehindCache();

        return $client instanceof Request && $client->getRequestUri() !== $request->getRequestUri();
    }

    public function clientRequest(Request $handled): Request
    {
        return $this->clientRequestBehindCache() ?? $handled;
    }

    private function clientRequestBehindCache(): ?Request
    {
        // getRequest() reads a property set by the first HttpCache::handle(), which also records a trace: without a
        // trace the kernel was called around HttpCache (a runtime calling http_kernel) and getRequest() would throw.
        if (!$this->httpCache instanceof HttpCache || $this->httpCache->getTraces() === []) {
            return null;
        }

        return $this->httpCache->getRequest();
    }
}
