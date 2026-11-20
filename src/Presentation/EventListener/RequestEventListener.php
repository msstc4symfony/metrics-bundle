<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Presentation\EventListener;

use Monolog\Attribute\WithMonologChannel;
use Msstc4Symfony\MetricsBundle\Application\Collector\RequestCollector;
use Msstc4Symfony\MetricsBundle\Application\LogChannel;
use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
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
 * @phpstan-import-type HttpMethod from BuiltInMetric
 *
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
     * @var WeakMap<Request, RequestMeasurementState>
     */
    private WeakMap $states;

    /**
     * The main request being measured until its terminate or a reset. With framework.http_cache the kernel handles a
     * clone of the client request, while terminate may receive the client original (observed on http-kernel 8.1.8) or
     * the forwarded clone (observed on 8.1.11): the original misses the identity lookup.
     */
    private ?Request $pendingMain = null;

    /**
     * @param iterable<int, HttpMeasurementObserverInterface> $observers
     * @param list<non-empty-string> $ignoredRoutes
     */
    public function __construct(
        private readonly RequestCollector $collector,
        #[AutowireIterator(HttpMeasurementObserverInterface::TAG)]
        private readonly iterable $observers,
        private readonly LoggerInterface $logger,
        private readonly array $ignoredRoutes,
        private readonly HttpCacheContext $httpCacheContext,
    ) {
        $this->states = new WeakMap();
    }

    public function onRequestFirst(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (
            !$event->isMainRequest()
            || $request->isMethod('OPTIONS')
            || $this->httpCacheContext->isForwardedFragment($request)
        ) {
            return;
        }

        $this->states[$request] = new RequestMeasurementState(hrtime(true));
        $this->pendingMain = $request;
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !isset($this->states[$request])) {
            return;
        }

        $requestRoute = $this->route($request);
        if ($this->isIgnored($requestRoute)) {
            unset($this->states[$request]);

            return;
        }

        try {
            $method = $this->method($this->httpCacheContext->clientRequest($request));
            $this->collector->incRequestTotal($method, $requestRoute);
            $this->states[$request]->markCounted();
        } catch (Throwable $e) {
            $this->logger->error('Cannot record HTTP request metrics', ['exception' => $e]);
        }
    }

    public function onException(ExceptionEvent $event): void
    {
        if ($event->isMainRequest()) {
            ($this->states[$event->getRequest()] ?? null)?->recordException($event->getThrowable());
        }
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $terminated = $event->getRequest();
        $request = $this->resolveTerminatedRequest($terminated);
        if (!$request instanceof Request) {
            return;
        }

        $state = $this->states[$request];
        unset($this->states[$request]);
        $duration = (hrtime(true) - $state->startedAt) / 1e9;
        $requestRoute = $this->route($request);

        if ($this->isIgnored($requestRoute)) {
            return;
        }

        // HttpCache forwards a HEAD miss as GET, and terminate gets either the client's request or the forwarded one.
        $method = $this->method($this->httpCacheContext->clientRequest($terminated));
        $status = $event->getResponse()->getStatusCode();

        try {
            // The router can throw before onRequest runs (404), so the request is counted here instead.
            if (!$state->isCounted()) {
                $this->collector->incRequestTotal($method, $requestRoute);
            }

            $this->collector->incResponseTotal($method, $requestRoute, $status);
            $this->collector->setRequestDuration($method, $requestRoute, $duration);
            $this->collector->setRequestDurationSummary($method, $requestRoute, $duration);
        } catch (Throwable $e) {
            $this->logger->error('Cannot record HTTP request metrics', ['exception' => $e]);
        }

        $this->notify($method, $requestRoute, $status, $duration, $state->exception());
    }

    #[Override]
    public function reset(): void
    {
        $this->states = new WeakMap();
        $this->pendingMain = null;
    }

    /**
     * Falls back to the pending main request when the terminated one is the http_cache original of a forwarded clone.
     */
    private function resolveTerminatedRequest(Request $terminated): ?Request
    {
        $pending = $this->pendingMain;
        $this->pendingMain = null;
        if (isset($this->states[$terminated])) {
            return $terminated;
        }

        $isSameRequest = $pending instanceof Request && $pending->getRequestUri() === $terminated->getRequestUri();

        return $isSameRequest && isset($this->states[$pending]) ? $pending : null;
    }

    /**
     * @param HttpMethod $method
     * @param non-empty-string|null $route
     */
    private function notify(string $method, ?string $route, int $status, float $duration, ?Throwable $exception): void
    {
        if (!HttpMeasurement::isRecordableStatus($status)) {
            $this->logger->error(
                'Cannot build HTTP measurement: status {status} is outside 100..599',
                ['status' => $status],
            );

            return;
        }

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
                $this->logger->error(
                    'Cannot notify HTTP measurement observer "{class}"',
                    ['class' => $observer::class, 'exception' => $e],
                );
            }
        }
    }

    /**
     * Any other method token (scanners send arbitrary ones) would create a new series per token.
     *
     * @return HttpMethod
     */
    private function method(Request $request): string
    {
        $method = $request->getMethod();

        return \in_array($method, BuiltInMetric::HTTP_METHODS, true) ? $method : 'OTHER';
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
}
