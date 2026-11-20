<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Presentation\EventListener;

use Msstc4Symfony\MetricsBundle\Application\Collector\RequestCollector;
use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricRepository;
use Msstc4Symfony\MetricsBundle\Application\Port\HttpMeasurement;
use Msstc4Symfony\MetricsBundle\Application\Port\HttpMeasurementObserverInterface;
use Msstc4Symfony\MetricsBundle\Infrastructure\Prometheus\PrometheusMetricRecorder;
use Msstc4Symfony\MetricsBundle\Presentation\EventListener\RequestEventListener;
use Msstc4Symfony\MetricsBundle\Test\Support\CollectingLogger;
use Msstc4Symfony\MetricsBundle\Test\Support\RecordingHttpMeasurementObserver;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;
use Msstc4Symfony\MetricsBundle\Test\Support\ThrowingMetricRecorder;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpCache\HttpCache;
use Symfony\Component\HttpKernel\HttpCache\StoreInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use WeakMap;

final class RequestEventListenerTest extends TestCase
{
    private CollectorRegistry $registry;

    private RequestCollector $collector;

    private KernelInterface $kernel;

    private CollectingLogger $logger;

    #[Override]
    protected function setUp(): void
    {
        if (!class_exists(RequestEvent::class)) {
            self::markTestSkipped('symfony/http-kernel not installed');
        }

        $this->registry = new CollectorRegistry(new InMemory());
        $this->collector = new RequestCollector(new PrometheusMetricRecorder($this->registry, new MetricRepository([]), 'app', 'cmp'));
        $this->kernel = self::createStub(KernelInterface::class);
        $this->logger = new CollectingLogger();
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

    public function testIgnoredRouteIsNotRecorded(): void
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
        $listener = new RequestEventListener($this->collector, [], $this->logger, ['custom-ignored'], null);

        $request = Request::create('/custom', Request::METHOD_GET);
        $request->attributes->set('_route', 'custom-ignored');

        $listener->onRequestFirst($this->requestEvent($request));
        $listener->onRequest($this->requestEvent($request));
        $listener->onTerminate(new TerminateEvent($this->kernel, $request, new Response('')));

        self::assertFalse(RegistrySamples::exists($this->registry, BuiltInMetric::HTTP_REQUEST));
        self::assertFalse(RegistrySamples::exists($this->registry, BuiltInMetric::HTTP_RESPONSE));
    }

    public function testObserverReceivesMeasurementAfterMetricsAreWritten(): void
    {
        $observer = new class($this->registry) implements HttpMeasurementObserverInterface {
            /** @var list<HttpMeasurement> */
            public array $measurements = [];

            public bool $responseSampleExisted = false;

            public function __construct(private readonly CollectorRegistry $registry)
            {
            }

            #[Override]
            public function onHttpMeasured(HttpMeasurement $measurement): void
            {
                $this->measurements[] = $measurement;
                $this->responseSampleExisted = RegistrySamples::exists($this->registry, BuiltInMetric::HTTP_RESPONSE);
            }
        };
        $listener = $this->listener([$observer]);

        $request = Request::create('/orders/42', Request::METHOD_GET);
        $request->attributes->set('_route', 'order_show');

        $listener->onRequestFirst($this->requestEvent($request));
        $listener->onRequest($this->requestEvent($request));
        usleep(1000);
        $listener->onTerminate(new TerminateEvent($this->kernel, $request, new Response('ok', Response::HTTP_CREATED)));

        self::assertCount(1, $observer->measurements);
        self::assertTrue($observer->responseSampleExisted);
        $measurement = $observer->measurements[0];
        self::assertSame('GET', $measurement->method);
        self::assertSame('order_show', $measurement->route);
        self::assertSame(201, $measurement->status);
        self::assertGreaterThan(0.0, $measurement->durationSeconds);
        self::assertNull($measurement->exception);
    }

    public function testObserverReceivesUncaughtException(): void
    {
        $observer = new RecordingHttpMeasurementObserver();
        $listener = $this->listener([$observer]);

        $request = Request::create('/orders/42', Request::METHOD_GET);
        $request->attributes->set('_route', 'order_show');

        $exception = new RuntimeException('boom');

        $listener->onRequestFirst($this->requestEvent($request));
        $listener->onRequest($this->requestEvent($request));
        $listener->onException(new ExceptionEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception));
        $listener->onTerminate(new TerminateEvent($this->kernel, $request, new Response('', Response::HTTP_INTERNAL_SERVER_ERROR)));

        self::assertCount(1, $observer->measurements);
        self::assertSame($exception, $observer->measurements[0]->exception);
        self::assertSame(500, $observer->measurements[0]->status);
        self::assertSame(0, $this->retainedStates($listener));
    }

    public function testSubRequestExceptionIsNotReportedToObserver(): void
    {
        $observer = new RecordingHttpMeasurementObserver();
        $listener = $this->listener([$observer]);

        $request = Request::create('/orders/42', Request::METHOD_GET);
        $subRequest = Request::create('/_error/500', Request::METHOD_GET);

        $listener->onRequestFirst($this->requestEvent($request));
        $listener->onException(new ExceptionEvent($this->kernel, $subRequest, HttpKernelInterface::SUB_REQUEST, new RuntimeException('sub')));
        $listener->onTerminate(new TerminateEvent($this->kernel, $request, new Response('')));

        self::assertCount(1, $observer->measurements);
        self::assertNull($observer->measurements[0]->exception);
        self::assertSame(0, $this->retainedStates($listener));
    }

    public function testExceptionOfUntrackedRequestIsNotRetained(): void
    {
        $listener = $this->listener();

        $request = Request::create('/orders/42', Request::METHOD_OPTIONS);
        $listener->onRequestFirst($this->requestEvent($request));
        $listener->onException(new ExceptionEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, new RuntimeException('boom')));

        self::assertSame(0, $this->retainedStates($listener));
    }

    public function testThrowingObserverDoesNotStopOthersAndIsLogged(): void
    {
        $throwing = new class implements HttpMeasurementObserverInterface {
            #[Override]
            public function onHttpMeasured(HttpMeasurement $measurement): void
            {
                throw new RuntimeException('observer failed');
            }
        };
        $recording = new RecordingHttpMeasurementObserver();
        $listener = $this->listener([$throwing, $recording]);

        $request = Request::create('/orders/42', Request::METHOD_GET);
        $request->attributes->set('_route', 'order_show');

        $listener->onRequestFirst($this->requestEvent($request));
        $listener->onRequest($this->requestEvent($request));
        $listener->onTerminate(new TerminateEvent($this->kernel, $request, new Response('ok')));

        self::assertCount(1, $recording->measurements);
        self::assertCount(1, $this->logger->records);
        self::assertStringStartsWith('error: Cannot notify HTTP measurement observer', $this->logger->records[0]);
        self::assertInstanceOf(RuntimeException::class, $this->logger->contexts[0]['exception'] ?? null);
        self::assertSame($throwing::class, $this->logger->contexts[0]['class'] ?? null);
    }

    public function testIgnoredRouteIsNotObserved(): void
    {
        $observer = new RecordingHttpMeasurementObserver();
        $listener = $this->listener([$observer]);

        $request = Request::create('/_/metrics', Request::METHOD_GET);
        $request->attributes->set('_route', 'metrics-get');

        $listener->onRequestFirst($this->requestEvent($request));
        $listener->onRequest($this->requestEvent($request));
        $listener->onTerminate(new TerminateEvent($this->kernel, $request, new Response('')));

        self::assertSame([], $observer->measurements);
    }

    public function testNonStandardMethodIsRecordedAsOther(): void
    {
        $observer = new RecordingHttpMeasurementObserver();
        $listener = $this->listener([$observer]);

        $request = Request::create('/dav', 'PROPFIND');
        $request->attributes->set('_route', 'dav');

        $listener->onRequestFirst($this->requestEvent($request));
        $listener->onRequest($this->requestEvent($request));
        $listener->onTerminate(new TerminateEvent($this->kernel, $request, new Response('', Response::HTTP_METHOD_NOT_ALLOWED)));

        self::assertSame(
            [[['app', 'cmp', 'OTHER', 'dav'], '1']],
            RegistrySamples::samples($this->registry, BuiltInMetric::HTTP_REQUEST),
        );
        self::assertSame(
            [['app', 'cmp', 'OTHER', 'dav', '405']],
            RegistrySamples::labels($this->registry, BuiltInMetric::HTTP_RESPONSE),
        );
        self::assertSame(
            [[['app', 'cmp', 'OTHER', 'dav'], '1']],
            RegistrySamples::samples($this->registry, BuiltInMetric::REQUEST_DURATION_HISTOGRAM_SECONDS, '_count'),
        );
        self::assertCount(1, $observer->measurements);
        self::assertSame('OTHER', $observer->measurements[0]->method);
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function standardMethods(): iterable
    {
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'] as $method) {
            yield $method => [$method];
        }
    }

    /**
     * @param non-empty-string $method
     */
    #[DataProvider('standardMethods')]
    public function testStandardMethodIsKept(string $method): void
    {
        $observer = new RecordingHttpMeasurementObserver();
        $listener = $this->listener([$observer]);

        $request = Request::create('/orders', $method);
        $listener->onRequestFirst($this->requestEvent($request));
        $listener->onTerminate(new TerminateEvent($this->kernel, $request, new Response('')));

        self::assertSame([['app', 'cmp', $method, 'unknown']], RegistrySamples::labels($this->registry, BuiltInMetric::HTTP_REQUEST));
        self::assertSame($method, $observer->measurements[0]->method ?? null);
    }

    public function testEarlyResponseBeforeRoutingListenerIsCountedOnceWithItsRoute(): void
    {
        $listener = $this->listener();

        // A firewall (priority 8) answers 401 after the router (32) set _route; onRequest (0) never runs.
        $request = Request::create('/orders/42', Request::METHOD_GET);
        $request->attributes->set('_route', 'order_show');

        $listener->onRequestFirst($this->requestEvent($request));
        $listener->onTerminate(new TerminateEvent($this->kernel, $request, new Response('', Response::HTTP_UNAUTHORIZED)));

        self::assertSame(
            [[['app', 'cmp', 'GET', 'order_show'], '1']],
            RegistrySamples::samples($this->registry, BuiltInMetric::HTTP_REQUEST),
        );
        self::assertSame(
            [[['app', 'cmp', 'GET', 'order_show', '401'], '1']],
            RegistrySamples::samples($this->registry, BuiltInMetric::HTTP_RESPONSE),
        );
    }

    public function testHttpCacheForwardedCloneIsRecordedWhenTerminateGetsTheOriginal(): void
    {
        $observer = new RecordingHttpMeasurementObserver();
        $listener = $this->listener([$observer]);

        // framework.http_cache: the kernel handles a clone, Kernel::terminate() passes the original.
        $original = Request::create('/orders/42', Request::METHOD_GET);
        $forwarded = clone $original;
        $forwarded->attributes->set('_route', 'order_show');

        $listener->onRequestFirst($this->requestEvent($forwarded));
        $listener->onRequest($this->requestEvent($forwarded));
        $listener->onTerminate(new TerminateEvent($this->kernel, $original, new Response('ok')));

        self::assertSame(
            [[['app', 'cmp', 'GET', 'order_show'], '1']],
            RegistrySamples::samples($this->registry, BuiltInMetric::HTTP_REQUEST),
        );
        self::assertSame(
            [[['app', 'cmp', 'GET', 'order_show', '200'], '1']],
            RegistrySamples::samples($this->registry, BuiltInMetric::HTTP_RESPONSE),
        );
        self::assertSame(
            [[['app', 'cmp', 'GET', 'order_show'], '1']],
            RegistrySamples::samples($this->registry, BuiltInMetric::REQUEST_DURATION_HISTOGRAM_SECONDS, '_count'),
        );
        self::assertCount(1, $observer->measurements);
        self::assertSame('order_show', $observer->measurements[0]->route);
        self::assertSame(0, $this->retainedStates($listener));

        $listener->onTerminate(new TerminateEvent($this->kernel, $original, new Response('ok')));
        self::assertCount(1, $observer->measurements);
    }

    public function testTerminateOfAnotherUriDoesNotRecordThePendingMainRequest(): void
    {
        $observer = new RecordingHttpMeasurementObserver();
        $listener = $this->listener([$observer]);

        $request = Request::create('/orders/42', Request::METHOD_GET);
        $listener->onRequestFirst($this->requestEvent($request));
        $listener->onTerminate(new TerminateEvent($this->kernel, Request::create('/other'), new Response('')));

        self::assertSame([], $observer->measurements);

        $listener->onTerminate(new TerminateEvent($this->kernel, $request, new Response('')));
        self::assertCount(1, $observer->measurements);
    }

    public function testTerminateOfAnotherUriDropsThePendingMainRequestFallback(): void
    {
        $observer = new RecordingHttpMeasurementObserver();
        $listener = $this->listener([$observer]);

        $original = Request::create('/orders/42', Request::METHOD_GET);
        $listener->onRequestFirst($this->requestEvent(clone $original));
        $listener->onTerminate(new TerminateEvent($this->kernel, Request::create('/other'), new Response('')));
        $listener->onTerminate(new TerminateEvent($this->kernel, $original, new Response('')));

        self::assertSame([], $observer->measurements);
    }

    public function testResetDropsThePendingMainRequestFallback(): void
    {
        $observer = new RecordingHttpMeasurementObserver();
        $listener = $this->listener([$observer]);

        $original = Request::create('/orders/42', Request::METHOD_GET);
        $listener->onRequestFirst($this->requestEvent(clone $original));
        $listener->reset();
        $listener->onTerminate(new TerminateEvent($this->kernel, $original, new Response('')));

        self::assertSame([], $observer->measurements);
    }

    public function testMainRequestAfterAnUnterminatedOneIsMeasured(): void
    {
        $observer = new RecordingHttpMeasurementObserver();
        $listener = $this->listener([$observer]);

        // A runtime that neither terminates nor resets between requests must not lose every later request.
        $abandoned = Request::create('/abandoned', Request::METHOD_GET);
        $listener->onRequestFirst($this->requestEvent($abandoned));
        $listener->onRequest($this->requestEvent($abandoned));

        $request = Request::create('/orders/42', Request::METHOD_POST);
        $request->attributes->set('_route', 'order_update');

        $listener->onRequestFirst($this->requestEvent($request));
        $listener->onRequest($this->requestEvent($request));
        $listener->onTerminate(new TerminateEvent($this->kernel, $request, new Response('')));

        self::assertSame(
            [[['app', 'cmp', 'POST', 'order_update', '200'], '1']],
            RegistrySamples::samples($this->registry, BuiltInMetric::HTTP_RESPONSE),
        );
        self::assertCount(1, $observer->measurements);
        self::assertSame('order_update', $observer->measurements[0]->route);
    }

    public function testHeadForwardedAsGetIsLabelledWithTheTerminatedRequestMethod(): void
    {
        $observer = new RecordingHttpMeasurementObserver();
        $listener = $this->listener([$observer]);

        // HttpCache::fetch() forwards a HEAD miss as GET; Kernel::terminate() passes the client's HEAD request.
        $original = Request::create('/orders/42', Request::METHOD_HEAD);
        $forwarded = clone $original;
        $forwarded->setMethod(Request::METHOD_GET);
        $forwarded->attributes->set('_route', 'order_show');

        $listener->onRequestFirst($this->requestEvent($forwarded));
        $listener->onTerminate(new TerminateEvent($this->kernel, $original, new Response('')));

        self::assertSame(
            [[['app', 'cmp', 'HEAD', 'order_show'], '1']],
            RegistrySamples::samples($this->registry, BuiltInMetric::HTTP_REQUEST),
        );
        self::assertSame(
            [[['app', 'cmp', 'HEAD', 'order_show', '200'], '1']],
            RegistrySamples::samples($this->registry, BuiltInMetric::HTTP_RESPONSE),
        );
        self::assertSame('HEAD', $observer->measurements[0]->method ?? null);
    }

    public function testHttpCacheFragmentOfACachedPageIsNotMeasured(): void
    {
        $observer = new RecordingHttpMeasurementObserver();
        $listener = $this->listenerBehindHttpCache(Request::create('/page'), [$observer]);

        // The page is a cache hit, so the fragment miss is the only request the kernel sees.
        $fragment = Request::create('/fragment');
        $fragment->attributes->set('_route', 'fragment');

        $listener->onRequestFirst($this->requestEvent($fragment));
        $listener->onRequest($this->requestEvent($fragment));
        $listener->onTerminate(new TerminateEvent($this->kernel, Request::create('/page'), new Response('')));

        self::assertSame(0, $this->retainedStates($listener));
        self::assertFalse(RegistrySamples::exists($this->registry, BuiltInMetric::HTTP_REQUEST));
        self::assertSame([], $observer->measurements);
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-string}>
     */
    public static function fragmentsSharingThePagePath(): iterable
    {
        yield 'same path, own query' => ['/products', '/products?partial=1'];
        yield 'controller reference' => ['/page', '/_fragment?_hash=abc&_path=_controller%3DApp%255CFragment'];
    }

    /**
     * @param non-empty-string $page
     * @param non-empty-string $fragmentUri
     */
    #[DataProvider('fragmentsSharingThePagePath')]
    public function testHttpCacheFragmentWithThePagePathIsNotMeasured(string $page, string $fragmentUri): void
    {
        $listener = $this->listenerBehindHttpCache(Request::create($page));

        $fragment = Request::create($fragmentUri);
        $fragment->attributes->set('_route', 'fragment');

        $listener->onRequestFirst($this->requestEvent($fragment));
        $listener->onRequest($this->requestEvent($fragment));

        self::assertSame(0, $this->retainedStates($listener));
        self::assertFalse(RegistrySamples::exists($this->registry, BuiltInMetric::HTTP_REQUEST));
    }

    public function testHttpCacheForwardedHeadIsCountedWithTheClientMethod(): void
    {
        $original = Request::create('/orders/42', Request::METHOD_HEAD);
        $listener = $this->listenerBehindHttpCache($original);

        $forwarded = clone $original;
        $forwarded->setMethod(Request::METHOD_GET);
        $forwarded->attributes->set('_route', 'order_show');

        $listener->onRequestFirst($this->requestEvent($forwarded));
        $listener->onRequest($this->requestEvent($forwarded));

        self::assertSame(
            [[['app', 'cmp', 'HEAD', 'order_show'], '1']],
            RegistrySamples::samples($this->registry, BuiltInMetric::HTTP_REQUEST),
        );
    }

    public function testHttpCacheThatHandledNoRequestIsIgnored(): void
    {
        $listener = $this->listenerBehindHttpCache(null);

        $request = Request::create('/orders/42', Request::METHOD_HEAD);
        $listener->onRequestFirst($this->requestEvent($request));
        $listener->onRequest($this->requestEvent($request));

        self::assertSame(
            [[['app', 'cmp', 'HEAD', 'unknown'], '1']],
            RegistrySamples::samples($this->registry, BuiltInMetric::HTTP_REQUEST),
        );
    }

    public function testStatusOutsideHttpRangeIsLoggedInsteadOfObserved(): void
    {
        $observer = new RecordingHttpMeasurementObserver();
        $listener = $this->listener([$observer]);

        $request = Request::create('/orders/42', Request::METHOD_GET);
        $listener->onRequestFirst($this->requestEvent($request));
        $listener->onTerminate(new TerminateEvent($this->kernel, $request, new class extends Response {
            // setStatusCode() rejects such a code; a subclass can still store one.
            public function __construct()
            {
                parent::__construct('');
                $this->statusCode = 600;
            }
        }));

        self::assertSame([], $observer->measurements);
        self::assertSame(['error: Cannot build HTTP measurement: status {status} is outside 100..599'], $this->logger->records);
        self::assertSame(600, $this->logger->contexts[0]['status'] ?? null);
    }

    public function testThrowingRecorderStillNotifiesObserversAndForgetsTheRequest(): void
    {
        $observer = new RecordingHttpMeasurementObserver();
        $listener = new RequestEventListener(new RequestCollector(new ThrowingMetricRecorder()), [$observer], $this->logger, [], null);

        $request = Request::create('/orders/42', Request::METHOD_GET);
        $request->attributes->set('_route', 'order_show');

        $listener->onRequestFirst($this->requestEvent($request));
        $listener->onRequest($this->requestEvent($request));
        $listener->onException(new ExceptionEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, new RuntimeException('boom')));
        $listener->onTerminate(new TerminateEvent($this->kernel, $request, new Response('', Response::HTTP_INTERNAL_SERVER_ERROR)));

        self::assertCount(1, $observer->measurements);
        self::assertSame(500, $observer->measurements[0]->status);
        self::assertSame(0, $this->retainedStates($listener));
        self::assertSame(
            ['error: Cannot record HTTP request metrics', 'error: Cannot record HTTP request metrics'],
            $this->logger->records,
        );
        self::assertInstanceOf(RuntimeException::class, $this->logger->contexts[1]['exception'] ?? null);
    }

    /**
     * @param list<HttpMeasurementObserverInterface> $observers
     */
    private function listener(array $observers = []): RequestEventListener
    {
        return new RequestEventListener($this->collector, $observers, $this->logger, [
            'metrics-get',
            'healthcheck-ping',
            'healthcheck-readiness',
            'healthcheck-liveliness',
        ], null);
    }

    /**
     * @param Request|null $clientRequest The request HttpCache is serving; null when it has handled none yet
     * @param list<HttpMeasurementObserverInterface> $observers
     */
    private function listenerBehindHttpCache(?Request $clientRequest, array $observers = []): RequestEventListener
    {
        $backend = self::createStub(HttpKernelInterface::class);
        $backend->method('handle')->willReturn(new Response(''));
        $httpCache = new HttpCache($backend, self::createStub(StoreInterface::class));
        if ($clientRequest instanceof Request) {
            $httpCache->handle($clientRequest);
        }

        return new RequestEventListener($this->collector, $observers, $this->logger, [], $httpCache);
    }

    private function retainedStates(RequestEventListener $listener): int
    {
        $map = (new ReflectionProperty($listener, 'states'))->getValue($listener);
        self::assertInstanceOf(WeakMap::class, $map);

        return \count($map);
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
