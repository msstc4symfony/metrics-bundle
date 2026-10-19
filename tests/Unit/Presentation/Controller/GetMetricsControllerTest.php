<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Presentation\Controller;

use Msstc4Symfony\MetricsBundle\Application\Port\MetricExport;
use Msstc4Symfony\MetricsBundle\Application\Port\MetricStorageException;
use Msstc4Symfony\MetricsBundle\Application\Port\MetricStorageInterface;
use Msstc4Symfony\MetricsBundle\Presentation\Controller\GetMetricsController;
use Msstc4Symfony\MetricsBundle\Test\Support\CollectingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

#[CoversClass(GetMetricsController::class)]
#[UsesClass(MetricExport::class)]
final class GetMetricsControllerTest extends TestCase
{
    public function testGetServesTheExportWithItsContentType(): void
    {
        $storage = self::createStub(MetricStorageInterface::class);
        $storage->method('export')->willReturn(new MetricExport("test_counter{app=\"test\"} 10\n", 'application/openmetrics-text; version=1.0.0'));

        $response = (new GetMetricsController($storage, new NullLogger()))->get();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame("test_counter{app=\"test\"} 10\n", $response->getContent());
        self::assertSame('application/openmetrics-text; version=1.0.0', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('max-age=0', (string) $response->headers->get('Cache-Control'));
    }

    public function testUnavailableStorageAnswers503AndLogs(): void
    {
        $storage = self::createStub(MetricStorageInterface::class);
        $storage->method('export')->willThrowException(new MetricStorageException("Can't connect to Redis server"));
        $logger = new CollectingLogger();

        $response = (new GetMetricsController($storage, $logger))->get();

        self::assertSame(503, $response->getStatusCode());
        self::assertSame("# metrics unavailable\n", $response->getContent());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        self::assertCount(1, $logger->records);
    }

    public function testThrowingLoggerKeepsThe503(): void
    {
        $storage = self::createStub(MetricStorageInterface::class);
        $storage->method('export')->willThrowException(new MetricStorageException("Can't connect to Redis server"));
        $logger = self::createStub(LoggerInterface::class);
        $logger->method('error')->willThrowException(new RuntimeException('log handler is down'));

        self::assertSame(503, (new GetMetricsController($storage, $logger))->get()->getStatusCode());
    }
}
