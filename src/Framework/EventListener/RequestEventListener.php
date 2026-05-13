<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Framework\EventListener;

use MaxShamaev\MetricsBundle\Infrastructure\Collector\RequestCollector;
use MaxShamaev\MetricsBundle\Presentation\Controller\GetMetricsController;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

#[AsEventListener(event: 'kernel.request', method: 'onRequestFirst', priority: 4096)]
#[AsEventListener(event: 'kernel.request', method: 'onRequest')]
#[AsEventListener(event: 'kernel.terminate', method: 'onTerminate')]
final class RequestEventListener
{
    private ?float $startedAt = null;

    /**
     * @param string[] $ignoredRoutes
     */
    public function __construct(
        private readonly RequestCollector $collector,
        private readonly array $ignoredRoutes = [
            GetMetricsController::ROUTE_NAME,
            'healthcheck-ping',
            'healthcheck-readiness',
            'healthcheck-liveliness',
        ],
    ) {
    }

    public function onRequestFirst(RequestEvent $event): void
    {
        $this->startedAt = null;

        if (!$event->isMainRequest() || $event->getRequest()->isMethod('OPTIONS')) {
            return;
        }

        $this->startedAt = microtime(true);
    }

    public function onRequest(RequestEvent $event): void
    {
        if ($this->startedAt === null) {
            return;
        }

        /** @var ?string $requestRoute */
        $requestRoute = $event->getRequest()->attributes->get('_route');
        if (in_array($requestRoute, $this->ignoredRoutes, true)) {
            $this->startedAt = null;

            return;
        }

        $this->collector->incRequestTotal($event->getRequest()->getMethod(), $requestRoute);
    }

    public function onTerminate(TerminateEvent $event): void
    {
        if ($this->startedAt === null) {
            return;
        }

        /** @var ?string $requestRoute */
        $requestRoute = $event->getRequest()->attributes->get('_route');

        $this->collector->incResponseTotal(
            $event->getRequest()->getMethod(),
            $requestRoute,
            $event->getResponse()->getStatusCode(),
        );

        $duration = microtime(true) - $this->startedAt;
        $this->collector->setRequestDuration(
            $event->getRequest()->getMethod(),
            $requestRoute,
            $duration,
        );

        $this->collector->setRequestDurationSummary(
            $event->getRequest()->getMethod(),
            $requestRoute,
            $duration,
        );
    }
}
