<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Infrastructure\Elastica;

use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\ElasticaCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Elastica\TimingHttpClient;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepository;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class TimingHttpClientTest extends TestCase
{
    private CollectorRegistry $registry;

    private ElasticaCollector $collector;

    protected function setUp(): void
    {
        if (!interface_exists(ClientInterface::class) || !class_exists(Psr17Factory::class)) {
            self::markTestSkipped('psr/http-client or nyholm/psr7 not installed');
        }

        $this->registry = new CollectorRegistry(new InMemory());
        $this->collector = new ElasticaCollector($this->registry, new MetricRepository([]), 'app', 'cmp');
    }

    public function testSuccessfulRequestRecordsCountAndDurationWithoutLeadingSlash(): void
    {
        $factory = new Psr17Factory();
        $inner = new readonly class($factory->createResponse(200)) implements ClientInterface {
            public function __construct(private ResponseInterface $response)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };

        new TimingHttpClient($inner, $this->collector)->sendRequest($factory->createRequest('POST', 'http://es:9200/index/_search'));

        self::assertSame([['app', 'cmp', 'POST', 'index/_search']], RegistrySamples::labels($this->registry, MetricLabelEnum::ELASTICA_REQUEST_SUCCESS));
        self::assertTrue(RegistrySamples::exists($this->registry, MetricLabelEnum::ELASTICA_REQUEST_DURATION_HISTOGRAM_SECONDS));

        $samples = RegistrySamples::samples($this->registry, MetricLabelEnum::ELASTICA_REQUEST_DURATION_HISTOGRAM_SECONDS, '_sum');
        self::assertCount(1, $samples);
        self::assertGreaterThanOrEqual(0.0, (float) $samples[0][1]);
        self::assertLessThan(1.0, (float) $samples[0][1]);
    }

    public function testErrorStatusWithElasticsearchErrorBodyCountsAsFailureAndKeepsTheResponse(): void
    {
        $factory = new Psr17Factory();
        $response = $factory->createResponse(404)->withBody($factory->createStream('{"error":{"type":"index_not_found_exception"}}'));

        $returned = $this->send($response, $factory->createRequest('GET', 'http://es:9200/missing/_doc/1'));

        self::assertSame($response, $returned);
        self::assertSame([['app', 'cmp', 'GET', 'missing/_doc/1']], RegistrySamples::labels($this->registry, MetricLabelEnum::ELASTICA_REQUEST_FAILED));
        self::assertFalse(RegistrySamples::exists($this->registry, MetricLabelEnum::ELASTICA_REQUEST_DURATION_HISTOGRAM_SECONDS));
    }

    public function testStatus400WithErrorBodyIsFailure(): void
    {
        $factory = new Psr17Factory();

        $this->send($factory->createResponse(400)->withBody($factory->createStream('{"error":"bad"}')), $factory->createRequest('GET', 'http://es:9200/bad'));

        self::assertSame([['app', 'cmp', 'GET', 'bad']], RegistrySamples::labels($this->registry, MetricLabelEnum::ELASTICA_REQUEST_FAILED));
        self::assertFalse(RegistrySamples::exists($this->registry, MetricLabelEnum::ELASTICA_REQUEST_SUCCESS));
    }

    public function testNotFoundDocumentWithoutErrorKeyIsSuccess(): void
    {
        $factory = new Psr17Factory();

        $this->send($factory->createResponse(404)->withBody($factory->createStream('{"_index":"i","_id":"1","found":false}')), $factory->createRequest('GET', 'http://es:9200/i/_doc/1'));

        self::assertSame([['app', 'cmp', 'GET', 'i/_doc/1']], RegistrySamples::labels($this->registry, MetricLabelEnum::ELASTICA_REQUEST_SUCCESS));
        self::assertTrue(RegistrySamples::exists($this->registry, MetricLabelEnum::ELASTICA_REQUEST_DURATION_HISTOGRAM_SECONDS));
        self::assertFalse(RegistrySamples::exists($this->registry, MetricLabelEnum::ELASTICA_REQUEST_FAILED));
    }

    public function testHeadNotFoundWithEmptyBodyIsSuccess(): void
    {
        $factory = new Psr17Factory();

        $this->send($factory->createResponse(404), $factory->createRequest('HEAD', 'http://es:9200/index'));

        self::assertSame([['app', 'cmp', 'HEAD', 'index']], RegistrySamples::labels($this->registry, MetricLabelEnum::ELASTICA_REQUEST_SUCCESS));
        self::assertTrue(RegistrySamples::exists($this->registry, MetricLabelEnum::ELASTICA_REQUEST_DURATION_HISTOGRAM_SECONDS));
    }

    public function testNonJsonObjectErrorBodyIsFailure(): void
    {
        $factory = new Psr17Factory();

        $this->send($factory->createResponse(502)->withBody($factory->createStream('<html>Bad Gateway</html>')), $factory->createRequest('GET', 'http://es:9200/x'));

        self::assertSame([['app', 'cmp', 'GET', 'x']], RegistrySamples::labels($this->registry, MetricLabelEnum::ELASTICA_REQUEST_FAILED));
    }

    public function testJsonListBodyOnErrorStatusIsFailure(): void
    {
        $factory = new Psr17Factory();

        $this->send($factory->createResponse(500)->withBody($factory->createStream('[1]')), $factory->createRequest('GET', 'http://es:9200/x'));

        self::assertSame([['app', 'cmp', 'GET', 'x']], RegistrySamples::labels($this->registry, MetricLabelEnum::ELASTICA_REQUEST_FAILED));
    }

    public function testBodyStaysFullyReadableAfterInspection(): void
    {
        $factory = new Psr17Factory();
        $body = '{"found":false}';

        $returned = $this->send($factory->createResponse(404)->withBody($factory->createStream($body)), $factory->createRequest('GET', 'http://es:9200/i/_doc/1'));

        self::assertSame($body, $returned->getBody()->getContents());
    }

    public function testBodyAlreadyConsumedByTheInnerClientIsStillInspected(): void
    {
        $factory = new Psr17Factory();
        $stream = $factory->createStream('{"error":"x"}');
        $stream->getContents();

        $this->send($factory->createResponse(404)->withBody($stream), $factory->createRequest('GET', 'http://es:9200/i'));

        self::assertSame([['app', 'cmp', 'GET', 'i']], RegistrySamples::labels($this->registry, MetricLabelEnum::ELASTICA_REQUEST_FAILED));
    }

    public function testNonSeekableErrorBodyIsFailureAndNotConsumed(): void
    {
        $factory = new Psr17Factory();
        $stream = new class implements StreamInterface {
            public bool $read = false;

            public function __toString(): string
            {
                $this->read = true;

                return '{"found":false}';
            }

            public function close(): void
            {
            }

            public function detach()
            {
                return null;
            }

            public function getSize(): ?int
            {
                return null;
            }

            public function tell(): int
            {
                return 0;
            }

            public function eof(): bool
            {
                return false;
            }

            public function isSeekable(): bool
            {
                return false;
            }

            public function seek(int $offset, int $whence = SEEK_SET): void
            {
            }

            public function rewind(): void
            {
            }

            public function isWritable(): bool
            {
                return false;
            }

            public function write(string $string): int
            {
                return 0;
            }

            public function isReadable(): bool
            {
                return true;
            }

            public function read(int $length): string
            {
                $this->read = true;

                return '';
            }

            public function getContents(): string
            {
                $this->read = true;

                return '';
            }

            public function getMetadata(?string $key = null)
            {
                return null;
            }
        };

        $this->send($factory->createResponse(404)->withBody($stream), $factory->createRequest('GET', 'http://es:9200/i/_doc/1'));

        self::assertFalse($stream->read);
        self::assertSame([['app', 'cmp', 'GET', 'i/_doc/1']], RegistrySamples::labels($this->registry, MetricLabelEnum::ELASTICA_REQUEST_FAILED));
    }

    public function testUnreadableErrorBodyIsFailureAndDoesNotEscape(): void
    {
        $factory = new Psr17Factory();
        $stream = self::createStub(StreamInterface::class);
        $stream->method('isSeekable')->willReturn(true);
        $stream->method('getContents')->willThrowException(new RuntimeException('broken stream'));
        $response = $factory->createResponse(500)->withBody($stream);

        $returned = $this->send($response, $factory->createRequest('GET', 'http://es:9200/x'));

        self::assertSame($response, $returned);
        self::assertSame([['app', 'cmp', 'GET', 'x']], RegistrySamples::labels($this->registry, MetricLabelEnum::ELASTICA_REQUEST_FAILED));
        self::assertFalse(RegistrySamples::exists($this->registry, MetricLabelEnum::ELASTICA_REQUEST_SUCCESS));
    }

    public function testBodyIsRewoundAgainAfterAFailedRead(): void
    {
        $factory = new Psr17Factory();
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('isSeekable')->willReturn(true);
        $stream->method('getContents')->willThrowException(new RuntimeException('broken stream'));
        $stream->expects(self::exactly(2))->method('rewind');

        $this->send($factory->createResponse(500)->withBody($stream), $factory->createRequest('GET', 'http://es:9200/x'));
    }

    public function testUnrewindableErrorBodyIsFailureAndDoesNotEscape(): void
    {
        $factory = new Psr17Factory();
        $stream = self::createStub(StreamInterface::class);
        $stream->method('isSeekable')->willReturn(true);
        $stream->method('rewind')->willThrowException(new RuntimeException('broken stream'));

        $this->send($factory->createResponse(404)->withBody($stream), $factory->createRequest('GET', 'http://es:9200/x'));

        self::assertSame([['app', 'cmp', 'GET', 'x']], RegistrySamples::labels($this->registry, MetricLabelEnum::ELASTICA_REQUEST_FAILED));
    }

    public function testQueryStringIsNotPartOfThePathLabel(): void
    {
        $factory = new Psr17Factory();

        $this->send($factory->createResponse(200), $factory->createRequest('GET', 'http://es:9200/index/_search?size=1'));

        self::assertSame([['app', 'cmp', 'GET', 'index/_search']], RegistrySamples::labels($this->registry, MetricLabelEnum::ELASTICA_REQUEST_SUCCESS));
    }

    private function send(ResponseInterface $response, RequestInterface $request): ResponseInterface
    {
        $inner = new readonly class($response) implements ClientInterface {
            public function __construct(private ResponseInterface $response)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };

        return new TimingHttpClient($inner, $this->collector)->sendRequest($request);
    }

    public function testClientExceptionCountsAsFailureAndIsRethrown(): void
    {
        $factory = new Psr17Factory();
        $inner = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class('down') extends RuntimeException implements ClientExceptionInterface {
                };
            }
        };

        try {
            new TimingHttpClient($inner, $this->collector)->sendRequest($factory->createRequest('GET', 'http://es:9200/_cluster/health'));
            self::fail('Expected the client exception to propagate');
        } catch (ClientExceptionInterface) {
        }

        self::assertSame([['app', 'cmp', 'GET', '_cluster/health']], RegistrySamples::labels($this->registry, MetricLabelEnum::ELASTICA_REQUEST_FAILED));
    }
}
