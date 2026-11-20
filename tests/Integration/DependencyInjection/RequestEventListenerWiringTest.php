<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\DependencyInjection;

use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Presentation\EventListener\RequestEventListener;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\TestKernel;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;
use Override;
use Prometheus\RegistryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\Service\ResetInterface;

final class RequestEventListenerWiringTest extends KernelTestCase
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

    public function testListenerIsResetWithTheKernelServices(): void
    {
        $kernel = self::bootKernel();
        $listener = self::getContainer()->get(RequestEventListener::class);
        self::assertInstanceOf(RequestEventListener::class, $listener);

        $request = Request::create('/orders/42');
        $listener->onRequestFirst(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        $resetter = self::getContainer()->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);
        $resetter->reset();

        $listener->onTerminate(new TerminateEvent($kernel, $request, new Response('')));

        $registry = self::getContainer()->get(RegistryInterface::class);
        self::assertInstanceOf(RegistryInterface::class, $registry);
        self::assertFalse(RegistrySamples::exists($registry, BuiltInMetric::HTTP_RESPONSE));
    }

    public function testDisabledHttpCacheIsNotKeptForTheListener(): void
    {
        self::bootKernel();

        self::assertFalse(self::getContainer()->has('http_cache'));
    }

    #[Override]
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }
}
