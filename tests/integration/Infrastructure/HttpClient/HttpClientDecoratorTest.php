<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Infrastructure\HttpClient;

use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\ExternalConnectionCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\HttpClient\HttpClientDecorator;
use Msstc4Symfony\MetricsBundle\Infrastructure\HttpClient\URLAssembler\AssemblerInterface;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepository;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\MetricFamilySamples;
use Prometheus\Sample;
use Prometheus\Storage\InMemory;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\ResetInterface;

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
            $this->samplesOf(MetricLabelEnum::HTTP_CONNECTION_REQUEST),
        );
        self::assertSame(
            [[['app', 'cmp', 'GET', 'api.example.com', '/users/:id', '201'], '1']],
            $this->samplesOf(MetricLabelEnum::HTTP_CONNECTION_RESPONSE),
        );
        self::assertTrue($this->familyExists(MetricLabelEnum::HTTP_CONNECTION_DURATION_HISTOGRAM_SECONDS));
    }

    public function testResponseIsRecordedOnlyWhenTheCallerReadsIt(): void
    {
        $response = $this->decorator(new MockHttpClient(new MockResponse('ok')))->request('GET', 'https://api.example.com/a');

        self::assertSame([], $this->samplesOf(MetricLabelEnum::HTTP_CONNECTION_RESPONSE), 'request() must not wait for the response');

        $response->getStatusCode();

        self::assertCount(1, $this->samplesOf(MetricLabelEnum::HTTP_CONNECTION_RESPONSE));
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

        self::assertCount(1, $this->samplesOf(MetricLabelEnum::HTTP_CONNECTION_REQUEST));
        self::assertSame([], $this->samplesOf(MetricLabelEnum::HTTP_CONNECTION_RESPONSE));
    }

    public function testKeepsRawPathWhenSanitizeDisabled(): void
    {
        $decorator = new HttpClientDecorator(new MockHttpClient(new MockResponse('')), $this->collector, [], sanitizePath: false);

        $decorator->request('GET', 'https://api.example.com/users/42')->getContent();

        self::assertSame(
            [[['app', 'cmp', 'GET', 'api.example.com', '/users/42'], '1']],
            $this->samplesOf(MetricLabelEnum::HTTP_CONNECTION_REQUEST),
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
            $this->samplesOf(MetricLabelEnum::HTTP_CONNECTION_REQUEST),
        );
    }

    public function testRelativeUrlIsResolvedAgainstBaseUriOption(): void
    {
        $decorator = $this->decorator(new MockHttpClient(new MockResponse('')));

        $decorator->request('GET', '/v1/items', ['base_uri' => 'https://svc.example.com'])->getContent();

        self::assertSame(
            [[['app', 'cmp', 'GET', 'svc.example.com', '/v1/items'], '1']],
            $this->samplesOf(MetricLabelEnum::HTTP_CONNECTION_REQUEST),
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

    private function decorator(HttpClientInterface $inner): HttpClientDecorator
    {
        return new HttpClientDecorator($inner, $this->collector, []);
    }

    /**
     * @return list<array{list<string>, string}>
     */
    private function samplesOf(MetricLabelEnum $metric): array
    {
        foreach ($this->registry->getMetricFamilySamples() as $family) {
            if ($family->getName() === 'symfony_' . $metric->value) {
                return array_values(array_map(
                    static fn (Sample $sample): array => [self::labels($sample), $sample->getValue()],
                    $family->getSamples(),
                ));
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private static function labels(Sample $sample): array
    {
        $labels = [];
        foreach ($sample->getLabelValues() as $value) {
            self::assertIsString($value);
            $labels[] = $value;
        }

        return $labels;
    }

    private function familyExists(MetricLabelEnum $metric): bool
    {
        return array_any(
            $this->registry->getMetricFamilySamples(),
            static fn (MetricFamilySamples $family): bool => $family->getName() === 'symfony_' . $metric->value,
        );
    }
}

interface ResettableClient extends HttpClientInterface, ResetInterface
{
}
