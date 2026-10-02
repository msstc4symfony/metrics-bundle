<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Infrastructure\HttpClient;

use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\ExternalConnectionCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\HttpClient\HttpClientDecorator;
use Msstc4Symfony\MetricsBundle\Infrastructure\HttpClient\URLAssembler\AssemblerInterface;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepository;
use Msstc4Symfony\MetricsBundle\Test\Integration\Infrastructure\HttpClient\Fixture\ResettableClient;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Response\StreamableInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class HttpClientDecoratorTest extends TestCase
{
    private CollectorRegistry $registry;

    private ExternalConnectionCollector $collector;

    protected function setUp(): void
    {
        if (!class_exists(MockHttpClient::class)) {
            self::markTestSkipped('symfony/http-client not installed');
        }

        $this->registry = new CollectorRegistry(new InMemory());
        $this->collector = new ExternalConnectionCollector($this->registry, new MetricRepository([]), 'app', 'cmp');
    }

    public function testRecordsRequestResponseAndDurationOnce(): void
    {
        $decorator = $this->decorator(new MockHttpClient(new MockResponse('{}', ['http_code' => 201])));

        $decorator->request('GET', 'https://api.example.com/users/42')->getContent();

        self::assertSame(
            [[['app', 'cmp', 'GET', 'api.example.com', '/users/:id'], '1']],
            RegistrySamples::samples($this->registry, MetricLabelEnum::HTTP_CONNECTION_REQUEST),
        );
        self::assertSame(
            [[['app', 'cmp', 'GET', 'api.example.com', '/users/:id', '201'], '1']],
            RegistrySamples::samples($this->registry, MetricLabelEnum::HTTP_CONNECTION_RESPONSE),
        );
        self::assertTrue(RegistrySamples::exists($this->registry, MetricLabelEnum::HTTP_CONNECTION_DURATION_HISTOGRAM_SECONDS));
    }

    public function testResponseIsRecordedOnlyWhenTheCallerReadsIt(): void
    {
        $response = $this->decorator(new MockHttpClient(new MockResponse('ok')))->request('GET', 'https://api.example.com/a');

        self::assertSame([], RegistrySamples::samples($this->registry, MetricLabelEnum::HTTP_CONNECTION_RESPONSE), 'request() must not wait for the response');

        $response->getStatusCode();

        self::assertCount(1, RegistrySamples::samples($this->registry, MetricLabelEnum::HTTP_CONNECTION_RESPONSE));
    }

    public function testTransportErrorReachesTheCallerWithoutResponseMetric(): void
    {
        $decorator = $this->decorator(new MockHttpClient(new MockResponse('', ['error' => 'host unreachable'])));

        $response = $decorator->request('GET', 'https://api.example.com/a');

        try {
            $response->getContent();
            self::fail('Transport error was swallowed');
        } catch (TransportException) {
        }

        self::assertCount(1, RegistrySamples::samples($this->registry, MetricLabelEnum::HTTP_CONNECTION_REQUEST));
        self::assertSame([], RegistrySamples::samples($this->registry, MetricLabelEnum::HTTP_CONNECTION_RESPONSE));
    }

    public function testKeepsRawPathWhenSanitizeDisabled(): void
    {
        $decorator = new HttpClientDecorator(new MockHttpClient(new MockResponse('')), $this->collector, [], sanitizePath: false);

        $decorator->request('GET', 'https://api.example.com/users/42')->getContent();

        self::assertSame(
            [[['app', 'cmp', 'GET', 'api.example.com', '/users/42'], '1']],
            RegistrySamples::samples($this->registry, MetricLabelEnum::HTTP_CONNECTION_REQUEST),
        );
    }

    public function testUrlAssemblerOverridesParsing(): void
    {
        $assembler = new class implements AssemblerInterface {
            public function assemble(string $url): array
            {
                return ['custom-host', '/custom-path'];
            }
        };

        $decorator = new HttpClientDecorator(new MockHttpClient(new MockResponse('')), $this->collector, [$assembler]);
        $decorator->request('GET', 'https://api.example.com/whatever')->getContent();

        self::assertSame(
            [[['app', 'cmp', 'GET', 'custom-host', '/custom-path'], '1']],
            RegistrySamples::samples($this->registry, MetricLabelEnum::HTTP_CONNECTION_REQUEST),
        );
    }

    public function testRelativeUrlIsResolvedAgainstBaseUriOption(): void
    {
        $decorator = $this->decorator(new MockHttpClient(new MockResponse('')));

        $decorator->request('GET', '/v1/items', ['base_uri' => 'https://svc.example.com'])->getContent();

        self::assertSame(
            [[['app', 'cmp', 'GET', 'svc.example.com', '/v1/items'], '1']],
            RegistrySamples::samples($this->registry, MetricLabelEnum::HTTP_CONNECTION_REQUEST),
        );
    }

    public function testResetAndWithOptionsAreForwardedToTheInnerClient(): void
    {
        $inner = $this->createMock(ResettableClient::class);
        $inner->expects(self::once())->method('reset');
        $inner->expects(self::once())->method('withOptions')->with(['timeout' => 1])->willReturnSelf();

        $decorator = $this->decorator($inner);
        $decorator->reset();

        self::assertNotSame($decorator, $decorator->withOptions(['timeout' => 1]));
    }

    public function testTimeoutKeepsTheExceptionClassOfTheBareClient(): void
    {
        // A server that accepts the connection and never answers: the real idle-timeout path.
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($server);
        $address = stream_socket_get_name($server, false);
        self::assertIsString($address);
        $url = 'http://' . $address . '/slow';

        try {
            self::assertSame(
                $this->exceptionClassOf(HttpClient::create(), $url),
                $this->exceptionClassOf($this->decorator(HttpClient::create()), $url),
            );
        } finally {
            fclose($server);
        }
    }

    public function testErrorStatusIsRecordedWhenGetContentThrows(): void
    {
        $response = $this->decorator(new MockHttpClient(new MockResponse('', ['http_code' => 404])))
            ->request('GET', 'https://api.example.com/missing')
        ;

        try {
            $response->getContent();
            self::fail('404 did not throw');
        } catch (ClientExceptionInterface) {
        }

        self::assertSame(
            [[['app', 'cmp', 'GET', 'api.example.com', '/missing', '404'], '1']],
            RegistrySamples::samples($this->registry, MetricLabelEnum::HTTP_CONNECTION_RESPONSE),
        );
    }

    public function testErrorStatusIsRecordedWhenToArrayThrows(): void
    {
        $response = $this->decorator(new MockHttpClient(new MockResponse('{}', ['http_code' => 500])))
            ->request('GET', 'https://api.example.com/broken')
        ;

        try {
            $response->toArray();
            self::fail('500 did not throw');
        } catch (ServerExceptionInterface) {
        }

        self::assertCount(1, RegistrySamples::samples($this->registry, MetricLabelEnum::HTTP_CONNECTION_RESPONSE));
    }

    public function testUnreadResponseStillRecordsItsStatus(): void
    {
        $this->decorator(new MockHttpClient(new MockResponse('ok', ['http_code' => 202])))
            ->request('POST', 'https://api.example.com/fire-and-forget')
        ;

        self::assertSame(
            [[['app', 'cmp', 'POST', 'api.example.com', '/fire-and-forget', '202'], '1']],
            RegistrySamples::samples($this->registry, MetricLabelEnum::HTTP_CONNECTION_RESPONSE),
        );
    }

    public function testBodyReadThroughToStreamRecordsDuration(): void
    {
        $response = $this->decorator(new MockHttpClient(new MockResponse(['a', 'b'])))
            ->request('GET', 'https://api.example.com/a')
        ;
        self::assertInstanceOf(StreamableInterface::class, $response);

        self::assertSame('ab', stream_get_contents($response->toStream()));
        self::assertSame('1', $this->durationCount());
    }

    public function testToStreamAfterGetContentStillHoldsTheBody(): void
    {
        $response = $this->decorator(new MockHttpClient(new MockResponse(['a', 'b'])))->request('GET', 'https://api.example.com/a');
        self::assertInstanceOf(StreamableInterface::class, $response);

        $response->getContent();

        self::assertSame('ab', stream_get_contents($response->toStream()));
    }

    public function testToStreamIsRewindable(): void
    {
        $response = $this->decorator(new MockHttpClient(new MockResponse(['a', 'b'])))->request('GET', 'https://api.example.com/a');
        self::assertInstanceOf(StreamableInterface::class, $response);
        $stream = $response->toStream();

        self::assertSame('ab', stream_get_contents($stream));
        self::assertTrue(rewind($stream));
        self::assertSame('ab', stream_get_contents($stream));
        self::assertSame('1', $this->durationCount());
    }

    public function testEachResponseIsRecordedOnceAcrossReadsAndDestruction(): void
    {
        $response = $this->decorator(new MockHttpClient(new MockResponse('ok')))->request('GET', 'https://api.example.com/a');
        $response->getStatusCode();
        $response->getContent();
        unset($response);

        self::assertSame(
            [[['app', 'cmp', 'GET', 'api.example.com', '/a', '200'], '1']],
            RegistrySamples::samples($this->registry, MetricLabelEnum::HTTP_CONNECTION_RESPONSE),
        );
        self::assertSame('1', $this->durationCount());
    }

    public function testCancelledRequestHasNoDuration(): void
    {
        $response = $this->decorator(new MockHttpClient(new MockResponse('ok')))->request('GET', 'https://api.example.com/a');
        $response->cancel();

        self::assertFalse(RegistrySamples::exists($this->registry, MetricLabelEnum::HTTP_CONNECTION_DURATION_HISTOGRAM_SECONDS));
    }

    public function testBaseUriFromWithOptionsIsUsedForTheHost(): void
    {
        $decorator = $this->decorator(new MockHttpClient(new MockResponse('')))
            ->withOptions(['base_uri' => 'https://svc.example.com'])
        ;

        $decorator->request('GET', '/v1/items')->getContent();

        self::assertSame(
            [[['app', 'cmp', 'GET', 'svc.example.com', '/v1/items'], '1']],
            RegistrySamples::samples($this->registry, MetricLabelEnum::HTTP_CONNECTION_REQUEST),
        );
    }

    public function testStreamingRecordsStatusAndDurationOnce(): void
    {
        $decorator = $this->decorator(new MockHttpClient(new MockResponse(['a', 'b'])));
        $response = $decorator->request('GET', 'https://api.example.com/a');

        foreach ($decorator->stream($response) as $streamed => $chunk) {
            self::assertSame($response, $streamed);
        }

        $response->getContent();

        self::assertCount(1, RegistrySamples::samples($this->registry, MetricLabelEnum::HTTP_CONNECTION_RESPONSE));
        self::assertSame('1', $this->durationCount());
    }

    /**
     * @return class-string<Throwable>|null
     */
    private function exceptionClassOf(HttpClientInterface $client, string $url): ?string
    {
        try {
            $client->request('GET', $url, ['timeout' => 0.2])->getStatusCode();
        } catch (Throwable $e) {
            return $e::class;
        }

        return null;
    }

    private function durationCount(): ?string
    {
        return RegistrySamples::samples($this->registry, MetricLabelEnum::HTTP_CONNECTION_DURATION_HISTOGRAM_SECONDS, '_count')[0][1] ?? null;
    }

    private function decorator(HttpClientInterface $inner): HttpClientDecorator
    {
        return new HttpClientDecorator($inner, $this->collector, []);
    }
}
