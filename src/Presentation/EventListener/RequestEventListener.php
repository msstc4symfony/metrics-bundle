<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Presentation\EventListener;

use Monolog\Attribute\WithMonologChannel;
use Msstc4Symfony\MetricsBundle\Application\Collector\RequestCollector;
use Msstc4Symfony\MetricsBundle\Application\LogChannel;
use Msstc4Symfony\MetricsBundle\Application\Port\HttpMeasurement;
use Msstc4Symfony\MetricsBundle\Application\Port\HttpMeasurementObserverInterface;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
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
#[WithMonologChannel(LogChannel::NAME)]
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
     * @param iterable<HttpMeasurementObserverInterface> $observers
     * @param list<non-empty-string> $ignoredRoutes
     */
    public function __construct(
        private readonly RequestCollector $collector,
        #[AutowireIterator(HttpMeasurementObserverInterface::TAG)]
        private readonly iterable $observers,
        private readonly LoggerInterface $logger,
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
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !isset($this->startedAt[$request])) {
            return;
        }

        $this->exceptions[$request] = $event->getThrowable();
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

        $status = $event->getResponse()->getStatusCode();
        $this->collector->incResponseTotal($request->getMethod(), $requestRoute, $status);

        $duration = microtime(true) - $startedAt;
        $this->collector->setRequestDuration($request->getMethod(), $requestRoute, $duration);
        $this->collector->setRequestDurationSummary($request->getMethod(), $requestRoute, $duration);

        $exception = $this->exceptions[$request] ?? null;
        $this->forget($request);

        $this->notify($request, $requestRoute, $status, $duration, $exception);
    }

    #[Override]
    public function reset(): void
    {
        $this->startedAt = new WeakMap();
        $this->counted = new WeakMap();
        $this->exceptions = new WeakMap();
    }

    private function notify(Request $request, ?string $route, int $status, float $duration, ?Throwable $exception): void
    {
        try {
            $measurement = new HttpMeasurement($request->getMethod(), $route, $status, max(0.0, $duration), $exception);
        } catch (Throwable $e) {
            $this->logger->error('Cannot build HTTP measurement', ['exception' => $e]);

            return;
        }

        foreach ($this->observers as $observer) {
            try {
                $observer->onHttpMeasured($measurement);
            } catch (Throwable $e) {
                $this->logger->error('Cannot notify HTTP measurement observer "{class}"', ['class' => $observer::class, 'exception' => $e]);
            }
        }
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
