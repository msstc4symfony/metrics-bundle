<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Presentation\EventListener;

use Msstc4Symfony\MetricsBundle\Application\Collector\RequestCollector;
use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricRepository;
use Msstc4Symfony\MetricsBundle\Infrastructure\Prometheus\PrometheusMetricRecorder;
use Msstc4Symfony\MetricsBundle\Presentation\EventListener\RequestEventListener;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;
use Override;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class RequestEventListenerTest extends TestCase
{
    private CollectorRegistry $registry;

    private RequestCollector $collector;

    private HttpKernelInterface $kernel;

    #[Override]
    protected function setUp(): void
    {
        if (!class_exists(RequestEvent::class)) {
            self::markTestSkipped('symfony/http-kernel not installed');
        }

        $this->registry = new CollectorRegistry(new InMemory());
        $this->collector = new RequestCollector(new PrometheusMetricRecorder($this->registry, new MetricRepository([]), 'app', 'cmp'));
        $this->kernel = self::createStub(HttpKernelInterface::class);
    }

    public function testMainRequestFlowRecordsAllMetrics(): void
    {
        $listener = $this->listener();

        $request = Request::create('/orders/42', Request::METHOD_GET);
        $request->attributes->set('_route', 'order_show');

        $listener->onRequestFirst($this->requestEvent($request));
        $listener->onRequest($this->requestEvent($request));
        $listener->onTerminate(new TerminateEvent($this->kernel, $request, new Response('ok', Response::HTTP_CREATED)));

        self::assertSame(
            [['app', 'cmp', 'GET', 'order_show']],
            RegistrySamples::labels($this->registry, BuiltInMetric::HTTP_REQUEST),
        );
        self::assertSame(
            [['app', 'cmp', 'GET', 'order_show', '201']],
            RegistrySamples::labels($this->registry, BuiltInMetric::HTTP_RESPONSE),
        );
        self::assertTrue(RegistrySamples::exists($this->registry, BuiltInMetric::REQUEST_DURATION_HISTOGRAM_SECONDS));
        self::assertTrue(RegistrySamples::exists($this->registry, BuiltInMetric::REQUEST_DURATION_SUMMARY_SECONDS));
    }

    public function testSubRequestIsIgnored(): void
    {
        $listener = $this->listener();

        $request = Request::create('/sub', Request::METHOD_GET);
        $listener->onRequestFirst($this->requestEvent($request, isMain: false));
        $listener->onRequest($this->requestEvent($request, isMain: false));

        self::assertFalse(RegistrySamples::exists($this->registry, BuiltInMetric::HTTP_REQUEST));
    }

    public function testOptionsRequestIsIgnored(): void
    {
        $listener = $this->listener();

        $request = Request::create('/anything', Request::METHOD_OPTIONS);
        $listener->onRequestFirst($this->requestEvent($request));
        $listener->onRequest($this->requestEvent($request));

        self::assertFalse(RegistrySamples::exists($this->registry, BuiltInMetric::HTTP_REQUEST));
    }

    public function testIgnoredRouteResetsStartedAt(): void
    {
        $listener = $this->listener();

        $request = Request::create('/_/metrics', Request::METHOD_GET);
        $request->attributes->set('_route', 'metrics-get');

        $listener->onRequestFirst($this->requestEvent($request));
        $listener->onRequest($this->requestEvent($request));
        $listener->onTerminate(new TerminateEvent($this->kernel, $request, new Response('', Response::HTTP_OK)));

        self::assertFalse(RegistrySamples::exists($this->registry, BuiltInMetric::HTTP_REQUEST));
        self::assertFalse(RegistrySamples::exists($this->registry, BuiltInMetric::HTTP_RESPONSE));
    }

    public function testSubRequestDoesNotResetMainRequestMeasurement(): void
    {
        $listener = $this->listener();

        $request = Request::create('/orders/42', Request::METHOD_GET);
        $request->attributes->set('_route', 'order_show');

        $subRequest = Request::create('/_error/500', Request::METHOD_GET);

        $listener->onRequestFirst($this->requestEvent($request));
        $listener->onRequest($this->requestEvent($request));
        $listener->onRequestFirst($this->requestEvent($subRequest, isMain: false));
        $listener->onRequest($this->requestEvent($subRequest, isMain: false));
        $listener->onTerminate(new TerminateEvent($this->kernel, $request, new Response('', Response::HTTP_INTERNAL_SERVER_ERROR)));

        self::assertSame(
            [['app', 'cmp', 'GET', 'order_show', '500']],
            RegistrySamples::labels($this->registry, BuiltInMetric::HTTP_RESPONSE),
        );
        self::assertTrue(RegistrySamples::exists($this->registry, BuiltInMetric::REQUEST_DURATION_HISTOGRAM_SECONDS));
    }

    public function testRequestWithoutRouteIsCountedAtTerminateAsUnknown(): void
    {
        $listener = $this->listener();

        $request = Request::create('/missing', Request::METHOD_GET);
        $listener->onRequestFirst($this->requestEvent($request));
        $listener->onTerminate(new TerminateEvent($this->kernel, $request, new Response('', Response::HTTP_NOT_FOUND)));

        self::assertSame(
            [['app', 'cmp', 'GET', 'unknown']],
            RegistrySamples::labels($this->registry, BuiltInMetric::HTTP_REQUEST),
        );
        self::assertSame(
            [['app', 'cmp', 'GET', 'unknown', '404']],
            RegistrySamples::labels($this->registry, BuiltInMetric::HTTP_RESPONSE),
        );
    }

    public function testRequestIsCountedOnceWhenOnRequestRan(): void
    {
        $listener = $this->listener();

        $request = Request::create('/orders/42', Request::METHOD_GET);
        $request->attributes->set('_route', 'order_show');

        $listener->onRequestFirst($this->requestEvent($request));
        $listener->onRequest($this->requestEvent($request));
        $listener->onTerminate(new TerminateEvent($this->kernel, $request, new Response('ok')));

        self::assertSame(
            [[['app', 'cmp', 'GET', 'order_show'], '1']],
            RegistrySamples::samples($this->registry, BuiltInMetric::HTTP_REQUEST),
        );
    }

    public function testResetDropsUnfinishedRequest(): void
    {
        $listener = $this->listener();

        $request = Request::create('/orders/42', Request::METHOD_GET);
        $request->attributes->set('_route', 'order_show');

        $listener->onRequestFirst($this->requestEvent($request));
        $listener->reset();
        $listener->onTerminate(new TerminateEvent($this->kernel, $request, new Response('ok')));

        self::assertFalse(RegistrySamples::exists($this->registry, BuiltInMetric::HTTP_RESPONSE));
    }

    public function testIgnoredRoutesComeFromConstructor(): void
    {
        $listener = new RequestEventListener($this->collector, ['custom-ignored']);

        $request = Request::create('/custom', Request::METHOD_GET);
        $request->attributes->set('_route', 'custom-ignored');

        $listener->onRequestFirst($this->requestEvent($request));
        $listener->onRequest($this->requestEvent($request));
        $listener->onTerminate(new TerminateEvent($this->kernel, $request, new Response('')));

        self::assertFalse(RegistrySamples::exists($this->registry, BuiltInMetric::HTTP_REQUEST));
        self::assertFalse(RegistrySamples::exists($this->registry, BuiltInMetric::HTTP_RESPONSE));
    }

    private function listener(): RequestEventListener
    {
        return new RequestEventListener($this->collector, [
            'metrics-get',
            'healthcheck-ping',
            'healthcheck-readiness',
            'healthcheck-liveliness',
        ]);
    }

    private function requestEvent(Request $request, bool $isMain = true): RequestEvent
    {
        return new RequestEvent(
            $this->kernel,
            $request,
            $isMain ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::SUB_REQUEST,
        );
    }
}
