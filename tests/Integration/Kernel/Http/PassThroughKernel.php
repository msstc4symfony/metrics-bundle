<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\Http;

use Override;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

// An application kernel wrapper around http_cache that is not an HttpCache itself.
final readonly class PassThroughKernel implements HttpKernelInterface, TerminableInterface
{
    public function __construct(
        #[AutowireDecorated]
        private HttpKernelInterface $inner,
    ) {
    }

    #[Override]
    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        return $this->inner->handle($request, $type, $catch);
    }

    #[Override]
    public function terminate(Request $request, Response $response): void
    {
        if ($this->inner instanceof TerminableInterface) {
            $this->inner->terminate($request, $response);
        }
    }
}
