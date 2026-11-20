<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\DependencyInjection;

use Monolog\Handler\TestHandler;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\TestKernel;
use Msstc4Symfony\MetricsBundle\Test\Support\RecordingHttpMeasurementObserver;
use Msstc4Symfony\MetricsBundle\Test\Support\ThrowingHttpMeasurementObserver;
use Override;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\TerminableInterface;

final class HttpMeasurementObserverWiringTest extends KernelTestCase
{
    private const DSN_ENV = 'METRICS_STORAGE_DSN';

    #[Override]
    protected function setUp(): void
    {
        if (!class_exists(MonologBundle::class)) {
            self::markTestSkipped('symfony/monolog-bundle not installed');
        }

        (new Filesystem())->remove(TestKernel::cacheRoot());
        $_SERVER[self::DSN_ENV] = 'inmemory://';
    }

    #[Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        unset($_SERVER[self::DSN_ENV]);
        (new Filesystem())->remove(TestKernel::cacheRoot());
    }

    public function testAutoconfiguredObserverSeesTheMeasuredRequestAndFailureIsLoggedOnMetricsChannel(): void
    {
        $kernel = self::bootKernel(['environment' => TestKernel::ENV_HTTP_OBSERVER]);
        self::assertInstanceOf(TerminableInterface::class, $kernel);

        $request = Request::create('/_test/ok');
        $kernel->terminate($request, $kernel->handle($request));

        $observer = self::getContainer()->get(RecordingHttpMeasurementObserver::class);
        self::assertInstanceOf(RecordingHttpMeasurementObserver::class, $observer);
        self::assertCount(1, $observer->measurements);
        self::assertSame('test_ok', $observer->measurements[0]->route);
        self::assertSame(200, $observer->measurements[0]->status);

        $metrics = self::getContainer()->get('monolog.handler.metrics');
        self::assertInstanceOf(TestHandler::class, $metrics);
        $records = $metrics->getRecords();
        self::assertCount(1, $records);
        self::assertStringStartsWith('Cannot notify HTTP measurement observer', $records[0]->message);
        self::assertSame(ThrowingHttpMeasurementObserver::class, $records[0]->context['class'] ?? null);
    }

    #[Override]
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }
}
