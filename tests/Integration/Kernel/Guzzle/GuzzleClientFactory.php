<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\Guzzle;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Clients answered by a MockHandler: one built by the container from its config, one by this factory, which the
 * bundle can only reach through a service configurator.
 */
final class GuzzleClientFactory
{
    /**
     * @return HandlerStack<callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>>
     */
    public static function stack(): HandlerStack
    {
        return HandlerStack::create(new MockHandler([new Response(302, ['Location' => 'https://example.com/b']), new Response(200)]));
    }

    public static function create(): Client
    {
        return new Client(['handler' => HandlerStack::create(new MockHandler([new Response(503)])), 'http_errors' => false]);
    }
}
