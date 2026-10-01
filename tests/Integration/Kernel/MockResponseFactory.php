<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Kernel;

use Symfony\Component\HttpClient\Response\MockResponse;

final readonly class MockResponseFactory
{
    public function __invoke(): MockResponse
    {
        return new MockResponse('{}');
    }
}
