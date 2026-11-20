<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Presentation\EventListener;

use Msstc4Symfony\MetricsBundle\Application\Collector\RequestCollector;
use Override;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;
use WeakMap;

/**
 * @internal
 */
#[AsEventListener(event: 'kernel.request', method: 'onRequestFirst', priority: 4096)]
#[AsEventListener(event: 'kernel.request', method: 'onRequest')]
#[AsEventListener(event: 'kernel.exception', method: 'onException', priority: 4096)]
#[AsEventListener(event: 'kernel.terminate', method: 'onTerminate')]
final class RequestEventListener implements ResetInterface
{
    /**
     * Keyed by the main request: the error sub-request issued by ErrorListener must not disturb it.
     *
     * @var WeakMap<Request, float>
     */
    private WeakMap $startedAt;

    /**
     * @var WeakMap<Request, true>
     */
    private WeakMap $counted;

    /**
     * @var WeakMap<Request, Throwable>
     */
    private WeakMap $exceptions;

    /**
     * @param list<string> $ignoredRoutes
     */
    public function __construct(
        private readonly RequestCollector $collector,
        private readonly array $ignoredRoutes,
    ) {
        $this->startedAt = new WeakMap();
        $this->counted = new WeakMap();
        $this->exceptions = new WeakMap();
    }

    public function onRequestFirst(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->isMethod('OPTIONS')) {
            return;
        }

        $this->startedAt[$request] = microtime(true);
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !isset($this->startedAt[$request])) {
            return;
        }

        $requestRoute = $this->route($request);
        if ($this->isIgnored($requestRoute)) {
            $this->forget($request);

            return;
        }

        $this->collector->incRequestTotal($request->getMethod(), $requestRoute);
        $this->counted[$request] = true;
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->exceptions[$event->getRequest()] = $event->getThrowable();
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        if (!isset($this->startedAt[$request])) {
            return;
        }

        $startedAt = $this->startedAt[$request];
        $requestRoute = $this->route($request);
        if ($this->isIgnored($requestRoute)) {
            $this->forget($request);

            return;
        }

        // The router can throw before onRequest runs (404), so the request is counted here instead.
        if (!isset($this->counted[$request])) {
            $this->collector->incRequestTotal($request->getMethod(), $requestRoute);
        }

        $this->collector->incResponseTotal(
            $request->getMethod(),
            $requestRoute,
            $event->getResponse()->getStatusCode(),
        );

        $duration = microtime(true) - $startedAt;
        $this->collector->setRequestDuration($request->getMethod(), $requestRoute, $duration);
        $this->collector->setRequestDurationSummary($request->getMethod(), $requestRoute, $duration);

        $this->forget($request);
    }

    #[Override]
    public function reset(): void
    {
        $this->startedAt = new WeakMap();
        $this->counted = new WeakMap();
        $this->exceptions = new WeakMap();
    }

    private function route(Request $request): ?string
    {
        $route = $request->attributes->get('_route');

        return \is_string($route) ? $route : null;
    }

    private function isIgnored(?string $route): bool
    {
        return $route !== null && \in_array($route, $this->ignoredRoutes, true);
    }

    private function forget(Request $request): void
    {
        unset($this->startedAt[$request], $this->counted[$request], $this->exceptions[$request]);
    }
}
