<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Guzzle;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use InvalidArgumentException;
use LogicException;
use Msstc4Symfony\MetricsBundle\Application\Collector\ExternalConnectionCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\OutgoingHttp\OutgoingRequestLabels;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Records the http_connection_* series for Guzzle requests through a handler-stack middleware. It sits right outside
 * Guzzle's "allow_redirects" middleware, inside "http_errors": one logical request is one record with the final
 * status, as with the curl transport of symfony/http-client, and error statuses arrive as responses.
 *
 * `AddGuzzleMonitorPass` wires it into container clients, through the client's `handler` config (`decorateHandler()`)
 * or as a service configurator (`addHandler()`).
 *
 * @phpstan-type GuzzleOptions array<array-key, mixed>
 * @phpstan-type GuzzleHandler callable(RequestInterface, GuzzleOptions): PromiseInterface
 *
 * @internal
 */
final readonly class MetricsGuzzleMiddleware
{
    public const MIDDLEWARE_NAME = 'msstc4symfony_metrics';

    private const REDIRECT_MIDDLEWARE_NAME = 'allow_redirects';

    public function __construct(
        private ExternalConnectionCollector $collector,
        private OutgoingRequestLabels $labels,
    ) {
    }

    /**
     * The given stack (or Guzzle's default one) with the metrics middleware. A bare handler is returned unchanged:
     * wrapping it into a stack would change what the client exposes as its handler.
     *
     * @param GuzzleHandler|null $handler
     *
     * @return GuzzleHandler
     */
    public function decorateHandler(?callable $handler = null): callable
    {
        if ($handler !== null && !$handler instanceof HandlerStack) {
            return $handler;
        }

        $stack = $handler ?? HandlerStack::create();
        $stack->remove(self::MIDDLEWARE_NAME);

        try {
            $stack->before(self::REDIRECT_MIDDLEWARE_NAME, $this->middleware(), self::MIDDLEWARE_NAME);
        } catch (InvalidArgumentException) {
            $stack->unshift($this->middleware(), self::MIDDLEWARE_NAME);
        }

        return $stack;
    }

    /**
     * Service configurator for clients that expose their handler through `getConfig()` (every Guzzle 7 client,
     * `GuzzleHttp\Client` on Guzzle 8).
     *
     * @throws LogicException when the client has no `getConfig()` (a Guzzle 8 `ClientInterface`)
     */
    public function addHandler(ClientInterface $client): void
    {
        if (!method_exists($client, 'getConfig')) {
            throw new LogicException(\sprintf('%s has no getConfig(): configure it as a %s with a literal config array or a %s handler.', $client::class, Client::class, HandlerStack::class));
        }

        $stack = $client->getConfig('handler');

        if ($stack instanceof HandlerStack) {
            $this->decorateHandler($stack);
        }
    }

    /**
     * @return Closure(GuzzleHandler): (Closure(RequestInterface, GuzzleOptions): PromiseInterface)
     */
    public function middleware(): Closure
    {
        return $this->wrap(...);
    }

    /**
     * @param GuzzleHandler $handler
     *
     * @return Closure(RequestInterface, GuzzleOptions): PromiseInterface
     */
    private function wrap(callable $handler): Closure
    {
        return fn (RequestInterface $request, array $options): PromiseInterface => $this->send($handler, $request, $options);
    }

    /**
     * @param GuzzleHandler $handler
     * @param GuzzleOptions $options
     */
    private function send(callable $handler, RequestInterface $request, array $options): PromiseInterface
    {
        try {
            $method = $request->getMethod();
            [$host, $path] = $this->labels->of((string) $request->getUri()->withUserInfo(''));
            $this->collector->incHTTPConnectionRequest($method, $host, $path);
        } catch (Throwable) {
            return $handler($request, $options);
        }

        $startedAt = hrtime(true);
        $record = function (ResponseInterface $response) use ($method, $host, $path, $startedAt): void {
            try {
                $this->collector->incHTTPConnectionResponse($method, $host, $path, $response->getStatusCode());
                $this->collector->setHTTPConnectionDuration($method, $host, $path, (hrtime(true) - $startedAt) / 1e9);
            } catch (Throwable) {
            }
        };

        return $handler($request, $options)->then(
            static function (mixed $response) use ($record): mixed {
                if ($response instanceof ResponseInterface) {
                    $record($response);
                }

                return $response;
            },
            static function (mixed $reason) use ($record): PromiseInterface {
                // Guzzle 7 declares getResponse() on RequestException (nullable), Guzzle 8 on ResponseException.
                $response = \is_object($reason) && method_exists($reason, 'getResponse') ? $reason->getResponse() : null;
                if ($response instanceof ResponseInterface) {
                    $record($response);
                }

                return Create::rejectionFor($reason);
            },
        );
    }
}
