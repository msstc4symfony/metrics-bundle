<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Framework\EventListener;

use Msstc4Symfony\MetricsBundle\Framework\EventListener\ExceptionEventListener;
use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\ErrorCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepository;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ExceptionEventListenerTest extends TestCase
{
    private CollectorRegistry $registry;

    private ErrorCollector $collector;

    protected function setUp(): void
    {
        if (!class_exists(ExceptionEvent::class)) {
            self::markTestSkipped('symfony/http-kernel not installed');
        }

        $this->registry = new CollectorRegistry(new InMemory());
        $this->collector = new ErrorCollector($this->registry, new MetricRepository([]), 'app', 'cmp', false);
    }

    public function testIncrementsExceptionCounter(): void
    {
        $listener = new ExceptionEventListener($this->collector);
        $kernel = self::createStub(HttpKernelInterface::class);

        $listener->onException(new ExceptionEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST, new RuntimeException('boom')));

        self::assertSame(
            [['app', 'cmp', RuntimeException::class]],
            RegistrySamples::labels($this->registry, MetricLabelEnum::EXCEPTION),
        );
    }

    public function testIgnoredExceptionsAreNotRecorded(): void
    {
        $listener = new ExceptionEventListener($this->collector);
        $kernel = self::createStub(HttpKernelInterface::class);

        $listener->onException(new ExceptionEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST, new NotFoundHttpException()));

        self::assertFalse(RegistrySamples::exists($this->registry, MetricLabelEnum::EXCEPTION));
    }
}
