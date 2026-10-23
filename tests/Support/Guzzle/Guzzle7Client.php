<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Support\Guzzle;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use LogicException;
use Override;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A Guzzle 7 client: ClientInterface::getConfig() returns its handler stack.
 */
final readonly class Guzzle7Client implements ClientInterface
{
    /**
     * @param HandlerStack<callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>> $handler
     */
    public function __construct(
        private HandlerStack $handler,
    ) {
    }

    /**
     * @return HandlerStack<callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>>|null
     */
    public function getConfig(?string $option = null): ?HandlerStack
    {
        return $option === 'handler' ? $this->handler : null;
    }

    /**
     * @param array<array-key, mixed> $options
     */
    #[Override]
    public function send(RequestInterface $request, array $options = []): ResponseInterface
    {
        throw new LogicException('Not used');
    }

    /**
     * @param array<array-key, mixed> $options
     */
    #[Override]
    public function sendAsync(RequestInterface $request, array $options = []): PromiseInterface
    {
        throw new LogicException('Not used');
    }

    /**
     * @param array<array-key, mixed> $options
     */
    #[Override]
    public function request(string $method, $uri, array $options = []): ResponseInterface
    {
        throw new LogicException('Not used');
    }

    /**
     * @param array<array-key, mixed> $options
     */
    #[Override]
    public function requestAsync(string $method, $uri, array $options = []): PromiseInterface
    {
        throw new LogicException('Not used');
    }
}
