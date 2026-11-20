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
    // Any other method token (scanners send arbitrary ones) would create a new series per token.
    private const RECORDED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'];

    private const OTHER_METHOD = 'OTHER';

    /**
     * Keyed by the main request: the error sub-request issued by ErrorListener must not disturb it.
     * Values are hrtime() nanoseconds.
     *
     * @var WeakMap<Request, float|int>
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
     * First main request since the last terminate or reset. With framework.http_cache the kernel handles a clone of
     * the main request, but Kernel::terminate() passes the original one, so the identity lookup misses.
     */
    private ?Request $pendingMain = null;

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

        $this->startedAt[$request] = hrtime(true);
        // ESI fragments forwarded by HttpCache are main requests too; they must not replace the page.
        $this->pendingMain ??= $request;
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

        try {
            $this->collector->incRequestTotal($this->method($request), $requestRoute);
            $this->counted[$request] = true;
        } catch (Throwable $e) {
            $this->logger->error('Cannot record HTTP request metrics', ['exception' => $e]);
        }
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
        $request = $this->measuredRequest($event->getRequest());
        if (!$request instanceof Request) {
            return;
        }

        $duration = (hrtime(true) - $this->startedAt[$request]) / 1e9;
        $requestRoute = $this->route($request);
        $isCounted = isset($this->counted[$request]);
        $exception = $this->exceptions[$request] ?? null;
        $this->forget($request);

        if ($this->isIgnored($requestRoute)) {
            return;
        }

        $method = $this->method($request);
        $status = $event->getResponse()->getStatusCode();

        try {
            // The router can throw before onRequest runs (404), so the request is counted here instead.
            if (!$isCounted) {
                $this->collector->incRequestTotal($method, $requestRoute);
            }

            $this->collector->incResponseTotal($method, $requestRoute, $status);
            $this->collector->setRequestDuration($method, $requestRoute, $duration);
            $this->collector->setRequestDurationSummary($method, $requestRoute, $duration);
        } catch (Throwable $e) {
            $this->logger->error('Cannot record HTTP request metrics', ['exception' => $e]);
        }

        $this->notify($method, $requestRoute, $status, $duration, $exception);
    }

    #[Override]
    public function reset(): void
    {
        $this->startedAt = new WeakMap();
        $this->counted = new WeakMap();
        $this->exceptions = new WeakMap();
        $this->pendingMain = null;
    }

    private function measuredRequest(Request $terminated): ?Request
    {
        $pending = $this->pendingMain;
        if (!$pending instanceof Request || $pending->getPathInfo() !== $terminated->getPathInfo()) {
            return isset($this->startedAt[$terminated]) ? $terminated : null;
        }

        $this->pendingMain = null;
        if (isset($this->startedAt[$terminated])) {
            return $terminated;
        }

        return isset($this->startedAt[$pending]) ? $pending : null;
    }

    /**
     * @param non-empty-string|null $route
     */
    private function notify(string $method, ?string $route, int $status, float $duration, ?Throwable $exception): void
    {
        try {
            $measurement = new HttpMeasurement($method, $route, $status, $duration, $exception);
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

    private function method(Request $request): string
    {
        $method = $request->getMethod();

        return \in_array($method, self::RECORDED_METHODS, true) ? $method : self::OTHER_METHOD;
    }

    /**
     * @return non-empty-string|null
     */
    private function route(Request $request): ?string
    {
        $route = $request->attributes->get('_route');

        return \is_string($route) && $route !== '' ? $route : null;
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
