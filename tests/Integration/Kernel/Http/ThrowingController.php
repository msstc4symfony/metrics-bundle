<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\Http;

use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class ThrowingController
{
    public function __construct(
        private HttpKernelInterface $kernel,
    ) {
    }

    #[Route('/_test/throw', name: 'test_throw', methods: 'GET')]
    public function throw(): never
    {
        throw new RuntimeException('boom');
    }

    #[Route('/_test/ok', name: 'test_ok', methods: 'GET')]
    public function ok(): Response
    {
        return new Response('ok');
    }

    #[Route('/_test/fragment', name: 'test_fragment', methods: 'GET')]
    public function fragment(): Response
    {
        return $this->kernel->handle(Request::create('/_test/ok'), HttpKernelInterface::SUB_REQUEST);
    }
}
