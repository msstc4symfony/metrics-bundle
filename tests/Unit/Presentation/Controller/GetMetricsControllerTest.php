<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Presentation\Controller;

use Msstc4Symfony\MetricsBundle\Presentation\Controller\GetMetricsController;
use Msstc4Symfony\MetricsBundle\Test\Support\CollectingLogger;
use PHPUnit\Framework\TestCase;
use Prometheus\Exception\StorageException;
use Prometheus\MetricFamilySamples;
use Prometheus\RegistryInterface;
use Prometheus\RenderTextFormat;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

final class GetMetricsControllerTest extends TestCase
{
    public function testGet(): void
    {
        $render = new RenderTextFormat();
        $registry = $this->createMock(RegistryInterface::class);
        $registry->expects(self::once())
            ->method('getMetricFamilySamples')
            ->willReturn(
                [
                    new MetricFamilySamples(
                        [
                            'name' => 'test_counter',
                            'type' => 'counter',
                            'help' => 'Test counter',
                            'labelNames' => ['app'],
                            'samples' => [
                                ['name' => 'test_counter', 'labelNames' => [], 'labelValues' => ['test'], 'value' => 10],
                            ],
                        ],
                    ),
                ],
            )
        ;

        $controller = new GetMetricsController($render, $registry, new NullLogger());
        $response = $controller->get();

        self::assertSame(
            <<<TXT
# HELP test_counter Test counter
# TYPE test_counter counter
test_counter{app="test"} 10

TXT,
            $response->getContent(),
        );
        self::assertSame(RenderTextFormat::MIME_TYPE, $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('max-age=0', (string) $response->headers->get('Cache-Control'));
    }

    public function testUnavailableStorageAnswers503AndLogs(): void
    {
        $registry = self::createStub(RegistryInterface::class);
        $registry->method('getMetricFamilySamples')->willThrowException(new StorageException("Can't connect to Redis server"));
        $logger = new CollectingLogger();

        $response = new GetMetricsController(new RenderTextFormat(), $registry, $logger)->get();

        self::assertSame(503, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        self::assertCount(1, $logger->records);
    }

    public function testThrowingLoggerKeepsThe503(): void
    {
        $registry = self::createStub(RegistryInterface::class);
        $registry->method('getMetricFamilySamples')->willThrowException(new StorageException("Can't connect to Redis server"));
        $logger = self::createStub(LoggerInterface::class);
        $logger->method('error')->willThrowException(new RuntimeException('log handler is down'));

        self::assertSame(503, new GetMetricsController(new RenderTextFormat(), $registry, $logger)->get()->getStatusCode());
    }
}
