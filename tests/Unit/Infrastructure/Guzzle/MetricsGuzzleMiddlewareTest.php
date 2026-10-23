<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Infrastructure\Guzzle;

use Generator;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LogicException;
use Msstc4Symfony\MetricsBundle\Application\Collector\ExternalConnectionCollector;
use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricDefinitionInterface;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricRepository;
use Msstc4Symfony\MetricsBundle\Application\Port\MetricRecorderInterface;
use Msstc4Symfony\MetricsBundle\Infrastructure\Guzzle\MetricsGuzzleMiddleware;
use Msstc4Symfony\MetricsBundle\Infrastructure\HttpClient\URLAssembler\AssemblerInterface;
use Msstc4Symfony\MetricsBundle\Infrastructure\OutgoingHttp\OutgoingRequestLabels;
use Msstc4Symfony\MetricsBundle\Infrastructure\Prometheus\PrometheusMetricRecorder;
use Msstc4Symfony\MetricsBundle\Test\Support\Guzzle\ClientWithoutConfig;
use Msstc4Symfony\MetricsBundle\Test\Support\Guzzle\Guzzle7Client;
use Msstc4Symfony\MetricsBundle\Test\Support\Guzzle\Guzzle7RequestException;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;
use Override;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ReflectionProperty;
use RuntimeException;

#[RequiresMethod(ClientInterface::class, 'send')]
final class MetricsGuzzleMiddlewareTest extends TestCase
{
    private CollectorRegistry $registry;

    private MetricsGuzzleMiddleware $middleware;

    #[Override]
    protected function setUp(): void
    {
        $this->registry = new CollectorRegistry(new InMemory());
        $this->middleware = $this->middleware(new PrometheusMetricRecorder($this->registry, new MetricRepository([]), 'app', 'cmp'));
    }

    public function testRecordsRequestResponseAndDurationOfASentRequest(): void
    {
        $response = $this->client(new Response(201))->request('POST', 'https://api.example.com/v1/orders/42?x=1');

        self::assertSame(201, $response->getStatusCode());
        self::assertSame([[['app', 'cmp', 'POST', 'api.example.com', '/v1/orders/:id'], '1']], $this->samples(BuiltInMetric::HTTP_CONNECTION_REQUEST));
        self::assertSame([[['app', 'cmp', 'POST', 'api.example.com', '/v1/orders/:id', '201'], '1']], $this->samples(BuiltInMetric::HTTP_CONNECTION_RESPONSE));
        self::assertSame([[['app', 'cmp', 'POST', 'api.example.com', '/v1/orders/:id'], '1']], $this->samples(BuiltInMetric::HTTP_CONNECTION_DURATION_HISTOGRAM_SECONDS, '_count'));
        $seconds = (float) $this->samples(BuiltInMetric::HTTP_CONNECTION_DURATION_HISTOGRAM_SECONDS, '_sum')[0][1];
        self::assertGreaterThan(0.0, $seconds);
        self::assertLessThan(5.0, $seconds);
    }

    public function testOneRecordCoversTheRedirectsWithTheFinalStatus(): void
    {
        $this->client(new Response(302, ['Location' => 'https://other.example.com/next']), new Response(200))->request('GET', 'https://example.com/start');

        self::assertSame([[['app', 'cmp', 'GET', 'example.com', '/start'], '1']], $this->samples(BuiltInMetric::HTTP_CONNECTION_REQUEST));
        self::assertSame([[['app', 'cmp', 'GET', 'example.com', '/start', '200'], '1']], $this->samples(BuiltInMetric::HTTP_CONNECTION_RESPONSE));
        self::assertSame('1', $this->durationCount());
    }

    public function testRetriesOfAPushedRetryMiddlewareAreOneRecord(): void
    {
        $stack = HandlerStack::create(new MockHandler([new Response(503), new Response(200)]));
        $stack->push(Middleware::retry(static fn (int $retries, RequestInterface $request, ?ResponseInterface $response): bool => $retries === 0 && $response?->getStatusCode() === 503, static fn (): int => 0), 'retry');

        $client = new Client(['handler' => $this->middleware->decorateHandler($stack)]);

        $client->request('GET', 'https://example.com/a');

        self::assertSame([[['app', 'cmp', 'GET', 'example.com', '/a', '200'], '1']], $this->samples(BuiltInMetric::HTTP_CONNECTION_RESPONSE));
    }

    public function testAnErrorStatusIsRecordedBeforeHttpErrorsRejectsIt(): void
    {
        try {
            $this->client(new Response(404))->request('GET', 'https://example.com/missing');
            self::fail('http_errors must reject a 404.');
        } catch (ClientException) {
        }

        self::assertSame([[['app', 'cmp', 'GET', 'example.com', '/missing', '404'], '1']], $this->samples(BuiltInMetric::HTTP_CONNECTION_RESPONSE));
        self::assertSame('1', $this->durationCount());
    }

    public function testATransportFailureOnlyCountsTheRequest(): void
    {
        $failure = new ConnectException('connection refused', new Request('GET', 'https://example.com'));

        try {
            $this->client($failure)->request('GET', 'https://example.com');
            self::fail('A connection failure must reach the caller.');
        } catch (ConnectException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertCount(1, $this->samples(BuiltInMetric::HTTP_CONNECTION_REQUEST));
        self::assertSame([], $this->samples(BuiltInMetric::HTTP_CONNECTION_RESPONSE));
        self::assertNull($this->durationCount());
    }

    public function testARejectionCarryingAResponseIsRecordedWithItsStatus(): void
    {
        $request = new Request('GET', 'https://example.com/a');
        $failure = new ServerException('server error', $request, new Response(503));
        $handler = $this->middleware->middleware()(static fn (): PromiseInterface => Create::rejectionFor($failure));

        try {
            $handler($request, [])->wait();
            self::fail('The rejection must reach the caller.');
        } catch (ServerException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame([[['app', 'cmp', 'GET', 'example.com', '/a', '503'], '1']], $this->samples(BuiltInMetric::HTTP_CONNECTION_RESPONSE));
        self::assertSame('1', $this->durationCount());
    }

    public function testRejectionsWithoutAResponseOnlyCountTheRequest(): void
    {
        $request = new Request('GET', 'https://example.com/a');

        foreach ([new RequestException('failed', $request), 'not an object'] as $reason) {
            $handler = $this->middleware->middleware()(static fn (): PromiseInterface => Create::rejectionFor($reason));
            $handler($request, [])->wait(false);
        }

        self::assertSame([[['app', 'cmp', 'GET', 'example.com', '/a'], '2']], $this->samples(BuiltInMetric::HTTP_CONNECTION_REQUEST));
        self::assertSame([], $this->samples(BuiltInMetric::HTTP_CONNECTION_RESPONSE));
        self::assertNull($this->durationCount());
    }

    public function testAFulfilmentWithoutAResponseOnlyCountsTheRequest(): void
    {
        $handler = $this->middleware->middleware()(static fn (): PromiseInterface => Create::promiseFor('not a response'));

        self::assertSame('not a response', $handler(new Request('GET', 'https://example.com'), [])->wait());
        self::assertCount(1, $this->samples(BuiltInMetric::HTTP_CONNECTION_REQUEST));
        self::assertSame([], $this->samples(BuiltInMetric::HTTP_CONNECTION_RESPONSE));
    }

    public function testAsynchronousRequestsAreRecordedWhenTheirPromisesSettle(): void
    {
        $client = $this->client(new Response(200), new Response(500));

        $first = $client->requestAsync('GET', 'https://a.example.com');
        $second = $client->requestAsync('GET', 'https://b.example.com', ['http_errors' => false]);

        self::assertSame([], $this->samples(BuiltInMetric::HTTP_CONNECTION_RESPONSE));

        Utils::all([$first, $second])->wait();

        self::assertEqualsCanonicalizing(
            [[['app', 'cmp', 'GET', 'a.example.com', '/', '200'], '1'], [['app', 'cmp', 'GET', 'b.example.com', '/', '500'], '1']],
            $this->samples(BuiltInMetric::HTTP_CONNECTION_RESPONSE),
        );
    }

    public function testAPoolRecordsEveryRequest(): void
    {
        $client = $this->client(new Response(200), new Response(201), new Response(202));
        $requests = static function (): Generator {
            foreach (['a', 'b', 'c'] as $host) {
                yield new Request('GET', 'https://' . $host . '.example.com');
            }
        };

        (new Pool($client, $requests(), ['concurrency' => 2]))->promise()->wait();

        self::assertCount(3, $this->samples(BuiltInMetric::HTTP_CONNECTION_RESPONSE));
        self::assertSame('3', $this->durationCount());
    }

    public function testTheMiddlewareSitsRightOutsideTheRedirects(): void
    {
        $stack = HandlerStack::create(new MockHandler([]));
        $stack->unshift(Middleware::mapRequest(static fn (RequestInterface $request): RequestInterface => $request), 'app_outermost');

        $this->middleware->decorateHandler($stack);

        self::assertSame(['app_outermost', 'http_errors', MetricsGuzzleMiddleware::MIDDLEWARE_NAME, 'allow_redirects'], \array_slice($this->names($stack), 0, 4));
    }

    public function testWithoutRedirectsTheMiddlewareIsOutermost(): void
    {
        $stack = HandlerStack::create(new MockHandler([]));
        $stack->remove('allow_redirects');

        $this->middleware->decorateHandler($stack);

        self::assertSame([MetricsGuzzleMiddleware::MIDDLEWARE_NAME, 'http_errors'], \array_slice($this->names($stack), 0, 2));
    }

    public function testDecoratingAStackTwiceKeepsOneMiddleware(): void
    {
        $stack = HandlerStack::create(new MockHandler([new Response(200)]));

        $this->middleware->decorateHandler($stack);
        $client = new Client(['handler' => $this->middleware->decorateHandler($stack)]);
        $client->request('GET', 'https://example.com');

        self::assertSame(['http_errors', MetricsGuzzleMiddleware::MIDDLEWARE_NAME, 'allow_redirects'], \array_slice($this->names($stack), 0, 3));
        self::assertSame([MetricsGuzzleMiddleware::MIDDLEWARE_NAME], array_values(array_filter($this->names($stack), static fn (string $name): bool => $name === MetricsGuzzleMiddleware::MIDDLEWARE_NAME)));
        self::assertSame([[['app', 'cmp', 'GET', 'example.com', '/'], '1']], $this->samples(BuiltInMetric::HTTP_CONNECTION_REQUEST));
    }

    public function testWithoutAStackDecoratingCreatesGuzzlesDefaultOne(): void
    {
        $stack = $this->middleware->decorateHandler();

        self::assertInstanceOf(HandlerStack::class, $stack);
        self::assertTrue($stack->hasHandler());
        self::assertContains(MetricsGuzzleMiddleware::MIDDLEWARE_NAME, $this->names($stack));
    }

    public function testABareHandlerIsReturnedUnchanged(): void
    {
        $handler = new MockHandler([]);

        self::assertSame($handler, $this->middleware->decorateHandler($handler));
    }

    public function testAddHandlerMeasuresAClientThroughItsConfig(): void
    {
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200)]))]);

        $this->middleware->addHandler($client);
        $client->request('GET', 'https://example.com');

        self::assertCount(1, $this->samples(BuiltInMetric::HTTP_CONNECTION_RESPONSE));
    }

    public function testAddHandlerLeavesAClientWithABareHandlerAlone(): void
    {
        $client = new Client(['handler' => new MockHandler([new Response(200)])]);

        $this->middleware->addHandler($client);
        $client->request('GET', 'https://example.com');

        self::assertSame([], $this->samples(BuiltInMetric::HTTP_CONNECTION_REQUEST));
    }

    public function testAddHandlerRejectsAClientWithoutConfig(): void
    {
        if (method_exists(ClientInterface::class, 'getConfig')) {
            self::markTestSkipped('Guzzle 7 clients always have getConfig().');
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(ClientWithoutConfig::class . ' has no getConfig(): configure it as a ' . Client::class . ' with a literal config array or a ' . HandlerStack::class . ' handler.');

        $this->middleware->addHandler(new ClientWithoutConfig());
    }

    public function testUrlAssemblersNeverSeeCredentials(): void
    {
        $assembler = new class implements AssemblerInterface {
            /** @var list<string> */
            public array $urls = [];

            #[Override]
            public function assemble(string $url): ?array
            {
                $this->urls[] = $url;

                return null;
            }
        };
        $middleware = new MetricsGuzzleMiddleware(
            new ExternalConnectionCollector(new PrometheusMetricRecorder($this->registry, new MetricRepository([]), 'app', 'cmp')),
            new OutgoingRequestLabels([$assembler], true),
        );
        $client = new Client(['handler' => $middleware->decorateHandler(HandlerStack::create(new MockHandler([new Response(200)])))]);

        $client->request('GET', 'https://user:secret@api.example.com/a?token=1');

        self::assertSame(['https://api.example.com/a?token=1'], $assembler->urls);
    }

    public function testTheMiddlewareSitsRightOutsideTheRedirectsOfAGuzzle7Stack(): void
    {
        // Guzzle 7 HandlerStack::create(): no "auth" middleware.
        $stack = HandlerStack::create(new MockHandler([]));
        foreach (['http_errors', 'allow_redirects', 'auth', 'cookies', 'prepare_body'] as $name) {
            $stack->remove($name);
        }

        $stack->push(Middleware::httpErrors(), 'http_errors');
        $stack->push(Middleware::redirect(), 'allow_redirects');
        $stack->push(Middleware::cookies(), 'cookies');
        $stack->push(Middleware::prepareBody(), 'prepare_body');

        $this->middleware->decorateHandler($stack);

        self::assertSame(['http_errors', MetricsGuzzleMiddleware::MIDDLEWARE_NAME, 'allow_redirects', 'cookies', 'prepare_body'], $this->names($stack));
    }

    public function testAGuzzle7RejectionWithoutAResponseOnlyCountsTheRequest(): void
    {
        $request = new Request('GET', 'https://example.com/a');
        $handler = $this->middleware->middleware()(static fn (): PromiseInterface => Create::rejectionFor(new Guzzle7RequestException(null)));

        $handler($request, [])->wait(false);

        self::assertCount(1, $this->samples(BuiltInMetric::HTTP_CONNECTION_REQUEST));
        self::assertSame([], $this->samples(BuiltInMetric::HTTP_CONNECTION_RESPONSE));
    }

    public function testAGuzzle7RejectionWithAResponseIsRecordedWithItsStatus(): void
    {
        $request = new Request('GET', 'https://example.com/a');
        $handler = $this->middleware->middleware()(static fn (): PromiseInterface => Create::rejectionFor(new Guzzle7RequestException(new Response(502))));

        $handler($request, [])->wait(false);

        self::assertSame([[['app', 'cmp', 'GET', 'example.com', '/a', '502'], '1']], $this->samples(BuiltInMetric::HTTP_CONNECTION_RESPONSE));
    }

    public function testAddHandlerMeasuresAGuzzle7ClientThroughItsInterfaceGetConfig(): void
    {
        $stack = HandlerStack::create(new MockHandler([new Response(200)]));

        $this->middleware->addHandler(new Guzzle7Client($stack));

        self::assertContains(MetricsGuzzleMiddleware::MIDDLEWARE_NAME, $this->names($stack));
    }

    public function testAFailingLabelAssemblerStillSendsTheRequest(): void
    {
        $assembler = new class implements AssemblerInterface {
            #[Override]
            public function assemble(string $url): never
            {
                throw new RuntimeException('assembler bug');
            }
        };
        $middleware = new MetricsGuzzleMiddleware(
            new ExternalConnectionCollector(new PrometheusMetricRecorder($this->registry, new MetricRepository([]), 'app', 'cmp')),
            new OutgoingRequestLabels([$assembler], true),
        );
        $client = new Client(['handler' => $middleware->decorateHandler(HandlerStack::create(new MockHandler([new Response(204)])))]);

        self::assertSame(204, $client->request('GET', 'https://example.com')->getStatusCode());
        self::assertSame([], $this->samples(BuiltInMetric::HTTP_CONNECTION_REQUEST));
    }

    public function testAFailingRecorderNeverReachesTheCaller(): void
    {
        $recorder = self::createStub(MetricRecorderInterface::class);
        $recorder->method('increment')->willReturnCallback(static function (MetricDefinitionInterface $metric): void {
            if ($metric === BuiltInMetric::HTTP_CONNECTION_RESPONSE) {
                throw new RuntimeException('recorder bug');
            }
        });
        $middleware = $this->middleware($recorder);
        $request = new Request('GET', 'https://example.com');

        $fulfilled = $middleware->middleware()(static fn (): PromiseInterface => Create::promiseFor(new Response(200)));
        $response = $fulfilled($request, [])->wait();
        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(200, $response->getStatusCode());

        $failure = new ServerException('server error', $request, new Response(500));
        $rejected = $middleware->middleware()(static fn (): PromiseInterface => Create::rejectionFor($failure));
        try {
            $rejected($request, [])->wait();
            self::fail('The rejection must reach the caller.');
        } catch (ServerException $caught) {
            self::assertSame($failure, $caught);
        }
    }

    public function testAHandlerThrowingSynchronouslyIsRethrownAfterCountingTheRequest(): void
    {
        $failure = new RuntimeException('handler bug');
        $handler = $this->middleware->middleware()(static fn (): never => throw $failure);

        try {
            $handler(new Request('GET', 'https://example.com'), []);
            self::fail('The handler exception must reach the caller.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertCount(1, $this->samples(BuiltInMetric::HTTP_CONNECTION_REQUEST));
        self::assertSame([], $this->samples(BuiltInMetric::HTTP_CONNECTION_RESPONSE));
    }

    /**
     * Middleware names from the outermost one: resolve() wraps the handler in reverse stack order.
     *
     * @param HandlerStack<callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>> $stack
     *
     * @return list<string>
     */
    private function names(HandlerStack $stack): array
    {
        $entries = (new ReflectionProperty(HandlerStack::class, 'stack'))->getValue($stack);
        self::assertIsArray($entries);

        $names = [];
        foreach ($entries as $entry) {
            self::assertIsArray($entry);
            $name = $entry[1] ?? null;
            if (\is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function middleware(MetricRecorderInterface $recorder): MetricsGuzzleMiddleware
    {
        return new MetricsGuzzleMiddleware(new ExternalConnectionCollector($recorder), new OutgoingRequestLabels([], true));
    }

    private function client(ResponseInterface|ConnectException ...$queue): Client
    {
        return new Client(['handler' => $this->middleware->decorateHandler(HandlerStack::create(new MockHandler($queue)))]);
    }

    /**
     * @return list<array{list<string>, string}>
     */
    private function samples(MetricDefinitionInterface $metric, string $suffix = ''): array
    {
        return RegistrySamples::samples($this->registry, $metric, $suffix);
    }

    private function durationCount(): ?string
    {
        $samples = $this->samples(BuiltInMetric::HTTP_CONNECTION_DURATION_HISTOGRAM_SECONDS, '_count');

        return $samples === [] ? null : (string) array_sum(array_map(static fn (array $sample): int => (int) $sample[1], $samples));
    }
}
