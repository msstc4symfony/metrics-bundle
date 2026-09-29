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
use Prometheus\Sample;
use Prometheus\Storage\InMemory;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class HttpClientDecoratorTest extends TestCase
{
    private CollectorRegistry $registry;

    private ExternalConnectionCollector $collector;

    protected function setUp(): void
    {
        if (!interface_exists(HttpClientInterface::class)) {
            self::markTestSkipped('symfony/http-client-contracts not installed');
        }

        $this->registry = new CollectorRegistry(new InMemory());
        $this->collector = new ExternalConnectionCollector($this->registry, new MetricRepository([]), 'app', 'cmp');
    }

    public function testRequestRecordsRequestResponseAndDurationMetrics(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getInfo')->willReturn(['start_time' => microtime(true) - 0.1]);

        $inner = $this->createMock(HttpClientInterface::class);
        $inner->expects(self::once())
            ->method('request')
            ->with('GET', 'https://api.example.com/users/42')
            ->willReturn($response)
        ;

        $decorator = new HttpClientDecorator($inner, $this->collector, [], sanitizePath: true);
        $decorator->request('GET', 'https://api.example.com/users/42');

        self::assertSame(
            [['app', 'cmp', 'GET', 'api.example.com', '/users/:id']],
            $this->labelValuesFor('symfony_' . MetricLabelEnum::HTTP_CONNECTION_REQUEST->value),
        );
        self::assertSame(
            [['app', 'cmp', 'GET', 'api.example.com', '/users/:id', '200']],
            $this->labelValuesFor('symfony_' . MetricLabelEnum::HTTP_CONNECTION_RESPONSE->value),
        );
        self::assertTrue($this->familyExists('symfony_' . MetricLabelEnum::HTTP_CONNECTION_DURATION_HISTOGRAM_SECONDS->value));
    }

    public function testRequestSkipsDurationWhenStartTimeIsZero(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(204);
        $response->method('getInfo')->willReturn(['start_time' => 0.0]);

        $inner = $this->createMock(HttpClientInterface::class);
        $inner->method('request')->willReturn($response);

        $decorator = new HttpClientDecorator($inner, $this->collector, []);
        $decorator->request('GET', 'https://api.example.com/');

        self::assertFalse($this->familyExists('symfony_' . MetricLabelEnum::HTTP_CONNECTION_DURATION_HISTOGRAM_SECONDS->value));
    }

    public function testRequestKeepsRawPathWhenSanitizeDisabled(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getInfo')->willReturn(['start_time' => 0.0]);

        $inner = $this->createMock(HttpClientInterface::class);
        $inner->method('request')->willReturn($response);

        $decorator = new HttpClientDecorator($inner, $this->collector, [], sanitizePath: false);
        $decorator->request('GET', 'https://api.example.com/users/42');

        self::assertSame(
            [['app', 'cmp', 'GET', 'api.example.com', '/users/42']],
            $this->labelValuesFor('symfony_' . MetricLabelEnum::HTTP_CONNECTION_REQUEST->value),
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

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getInfo')->willReturn(['start_time' => 0.0]);

        $inner = $this->createMock(HttpClientInterface::class);
        $inner->method('request')->willReturn($response);

        $decorator = new HttpClientDecorator($inner, $this->collector, [$assembler]);
        $decorator->request('GET', 'https://api.example.com/whatever');

        self::assertSame(
            [['app', 'cmp', 'GET', 'custom-host', '/custom-path']],
            $this->labelValuesFor('symfony_' . MetricLabelEnum::HTTP_CONNECTION_REQUEST->value),
        );
    }

    public function testRelativeUrlIsResolvedAgainstBaseUri(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getInfo')->willReturn(['start_time' => 0.0]);

        $inner = $this->createMock(HttpClientInterface::class);
        $inner->method('request')->willReturn($response);

        $decorator = new HttpClientDecorator($inner, $this->collector, [], baseUri: 'https://api.example.com');
        $decorator->request('GET', '/orders');

        self::assertSame(
            [['app', 'cmp', 'GET', 'api.example.com', '/orders']],
            $this->labelValuesFor('symfony_' . MetricLabelEnum::HTTP_CONNECTION_REQUEST->value),
        );
    }

    public function testWithOptionsReturnsCloneWithoutMutatingOriginal(): void
    {
        $inner = $this->createMock(HttpClientInterface::class);
        $newInner = $this->createMock(HttpClientInterface::class);
        $inner->expects(self::once())
            ->method('withOptions')
            ->with(['timeout' => 1])
            ->willReturn($newInner)
        ;

        $decorator = new HttpClientDecorator($inner, $this->collector, []);
        $clone = $decorator->withOptions(['timeout' => 1]);

        self::assertNotSame($decorator, $clone);
    }

    /**
     * @return array<array<int, string>>
     */
    private function labelValuesFor(string $name): array
    {
        foreach ($this->registry->getMetricFamilySamples() as $family) {
            if ($family->getName() !== $name) {
                continue;
            }

            return array_map(
                static fn (Sample $sample): array => $sample->getLabelValues(),
                $family->getSamples(),
            );
        }

        return [];
    }

    private function familyExists(string $name): bool
    {
        return array_any($this->registry->getMetricFamilySamples(), fn ($family): bool => $family->getName() === $name);
    }
}
