<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Infrastructure\Cache;

use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\Cache\EarlyComputer;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\Cache\EarlyExpirationKernel;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\TestKernel;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;
use Override;
use PHPUnit\Framework\TestCase;
use Prometheus\RegistryInterface;
use Symfony\Component\Cache\Messenger\EarlyExpirationMessage;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Contracts\Cache\CacheInterface;

final class EarlyExpirationMetricsTest extends TestCase
{
    private ?EarlyExpirationKernel $kernel = null;

    #[Override]
    protected function setUp(): void
    {
        if (!class_exists(WorkerRunningEvent::class)) {
            self::markTestSkipped('symfony/messenger not installed');
        }

        (new Filesystem())->remove(TestKernel::cacheRoot());
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        (new Filesystem())->remove(TestKernel::cacheRoot());
    }

    public function testAnEarlyRecomputationIsStillSentToTheBusAndCountedAsAHit(): void
    {
        $this->kernel = new EarlyExpirationKernel();
        $this->kernel->boot();

        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $pool = $container->get(EarlyExpirationKernel::POOL);
        $computer = $container->get(EarlyComputer::class);
        $transport = $container->get(EarlyExpirationKernel::TRANSPORT);
        self::assertInstanceOf(CacheInterface::class, $pool);
        self::assertInstanceOf(EarlyComputer::class, $computer);
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        self::assertSame('value 1', $pool->get('a', $computer));
        self::assertSame('value 1', $pool->get('a', $computer, \INF), 'the stale value is served while the bus recomputes');

        self::assertSame(1, $computer->computations);
        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        self::assertInstanceOf(EarlyExpirationMessage::class, $sent[0]->getMessage());

        $request = Request::create('/_/metrics');
        $this->kernel->terminate($request, $this->kernel->handle($request));
        $registry = $container->get(RegistryInterface::class);
        self::assertInstanceOf(RegistryInterface::class, $registry);
        self::assertEqualsCanonicalizing(
            [
                [['unknown', 'unknown', EarlyExpirationKernel::POOL, 'hit'], '1'],
                [['unknown', 'unknown', EarlyExpirationKernel::POOL, 'miss'], '1'],
            ],
            RegistrySamples::samples($registry, BuiltInMetric::CACHE_REQUEST),
        );
    }
}
