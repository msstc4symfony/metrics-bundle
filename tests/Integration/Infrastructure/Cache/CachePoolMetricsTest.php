<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Infrastructure\Cache;

use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Infrastructure\Cache\CountingCacheAdapter;
use Msstc4Symfony\MetricsBundle\Infrastructure\Cache\CountingTagAwareCacheAdapter;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\Cache\CachePoolsConsumer;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\Cache\CachePoolsKernel;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\TestKernel;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;
use Override;
use PHPUnit\Framework\TestCase;
use Prometheus\RegistryInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TraceableAdapter;
use Symfony\Component\Cache\DataCollector\CacheDataCollector;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Cache\ItemInterface;

final class CachePoolMetricsTest extends TestCase
{
    private ?CachePoolsKernel $kernel = null;

    #[Override]
    protected function setUp(): void
    {
        (new Filesystem())->remove(TestKernel::cacheRoot());
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        (new Filesystem())->remove(TestKernel::cacheRoot());
    }

    public function testListedPoolsAreCountedOncePerLookupAndWrittenOnTerminate(): void
    {
        $kernel = $this->boot();
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $pools = $container->get(CachePoolsConsumer::class);
        self::assertInstanceOf(CachePoolsConsumer::class, $pools);

        self::assertInstanceOf(CountingCacheAdapter::class, $pools->plain);
        self::assertInstanceOf(CountingTagAwareCacheAdapter::class, $pools->tagged);
        self::assertNotInstanceOf(CountingCacheAdapter::class, $pools->unlisted);

        $tagged = static function (ItemInterface $item): string {
            $item->tag(['users']);

            return 'value';
        };
        $pools->plain->get('a', static fn (): string => 'value');
        $pools->plain->get('a', static fn (): string => 'value');
        $pools->plainTaggable->get('b', $tagged);
        $pools->plainTaggable->get('b', $tagged);
        $pools->tagged->get('c', $tagged);
        $pools->tagged->get('c', $tagged);
        $pools->tagged->get('c', $tagged);
        $pools->unlisted->getItem('d');
        $pools->chain->get('e', static fn (): string => 'value');
        $pools->chain->get('e', static fn (): string => 'other');

        $registry = $container->get(RegistryInterface::class);
        self::assertInstanceOf(RegistryInterface::class, $registry);
        self::assertSame([], RegistrySamples::samples($registry, BuiltInMetric::CACHE_REQUEST), 'written once the request terminates');

        $request = Request::create('/_/metrics');
        $kernel->terminate($request, $kernel->handle($request));

        self::assertEqualsCanonicalizing(
            [
                [['unknown', 'unknown', CachePoolsKernel::PLAIN_POOL, 'hit'], '2'],
                [['unknown', 'unknown', CachePoolsKernel::PLAIN_POOL, 'miss'], '2'],
                [['unknown', 'unknown', CachePoolsKernel::TAGGED_POOL, 'hit'], '2'],
                [['unknown', 'unknown', CachePoolsKernel::TAGGED_POOL, 'miss'], '1'],
                [['unknown', 'unknown', CachePoolsKernel::CHAIN_POOL, 'hit'], '1'],
                [['unknown', 'unknown', CachePoolsKernel::CHAIN_POOL, 'miss'], '1'],
            ],
            RegistrySamples::samples($registry, BuiltInMetric::CACHE_REQUEST),
        );
    }

    public function testThePruneCommandKeepsThePoolName(): void
    {
        $tester = new CommandTester((new Application($this->boot()))->find('cache:pool:prune'));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Pruning cache pool: ' . CachePoolsKernel::FILES_POOL, $tester->getDisplay());
        self::assertStringNotContainsString('msstc4symfony_metrics.cache.', $tester->getDisplay());
    }

    public function testTheProfilerWrapsTheCountingPoolsUnderTheirNames(): void
    {
        $kernel = $this->boot(profiler: true);
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $plain = $container->get(CachePoolsKernel::PLAIN_POOL);
        self::assertInstanceOf(TraceableAdapter::class, $plain);
        self::assertInstanceOf(CountingCacheAdapter::class, $plain->getPool());
        $plain->get('a', static fn (): string => 'value');

        $collector = $container->get('data_collector.cache');
        self::assertInstanceOf(CacheDataCollector::class, $collector);
        $collector->lateCollect();
        $adapters = $collector->getAdapters();

        self::assertSame(CountingCacheAdapter::class, $adapters[CachePoolsKernel::PLAIN_POOL] ?? null);
        // Symfony profiles a "tags: true" pool through its inner pool, which the bundle leaves alone.
        self::assertSame(ArrayAdapter::class, $adapters[CachePoolsKernel::TAGGED_POOL] ?? null);
        self::assertSame([], array_filter(array_keys($adapters), static fn (string $name): bool => str_starts_with($name, 'msstc4symfony_metrics.')));

        $request = Request::create('/_/metrics');
        $kernel->terminate($request, $kernel->handle($request));
        $registry = $container->get(RegistryInterface::class);
        self::assertInstanceOf(RegistryInterface::class, $registry);
        self::assertSame([[['unknown', 'unknown', CachePoolsKernel::PLAIN_POOL, 'miss'], '1']], RegistrySamples::samples($registry, BuiltInMetric::CACHE_REQUEST));
    }

    private function boot(bool $profiler = false): CachePoolsKernel
    {
        $this->kernel = new CachePoolsKernel($profiler);
        $this->kernel->boot();

        return $this->kernel;
    }
}
