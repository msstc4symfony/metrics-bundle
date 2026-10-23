<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\DependencyInjection;

use LogicException;
use Msstc4Symfony\MetricsBundle\Application\Collector\ErrorCollector;
use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricRepository;
use Msstc4Symfony\MetricsBundle\Infrastructure\Cache\CountingCacheAdapter;
use Msstc4Symfony\MetricsBundle\Presentation\EventListener\MessengerEventListener;
use Msstc4Symfony\MetricsBundle\Test\Integration\DependencyInjection\Fixture\ExtraMetricEnum;
use Msstc4Symfony\MetricsBundle\Test\Integration\DependencyInjection\Fixture\IntBackedMetricEnum;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\BundleConfigKernel;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\TestKernel;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prometheus\RegistryInterface;
use RuntimeException;
use stdClass;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;

/**
 * @phpstan-import-type MetricsConfigShape from BundleConfigKernel
 */
final class MetricsBundleConfigTest extends TestCase
{
    private const ENV_VARS = ['METRICS_STORAGE_DSN', 'APPLICATION_NAME', 'COMPONENT_NAME', 'METRICS_TEST_BACKOFF'];

    private ?BundleConfigKernel $kernel = null;

    #[Override]
    protected function setUp(): void
    {
        (new Filesystem())->remove(TestKernel::cacheRoot());
        foreach (self::ENV_VARS as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
        }
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        foreach (self::ENV_VARS as $name) {
            unset($_SERVER[$name]);
        }

        (new Filesystem())->remove(TestKernel::cacheRoot());
    }

    public function testDefaultsWithoutBundleConfig(): void
    {
        $container = $this->bootKernel(null)->getContainer();

        self::assertSame('redis://127.0.0.1:6379', $container->getParameter('msstc4symfony_metrics.storage.dsn'));
        self::assertSame(5.0, $container->getParameter('msstc4symfony_metrics.storage.reconnect_backoff_seconds'));
        self::assertSame('unknown', $container->getParameter('msstc4symfony_metrics.application_name'));
        self::assertSame('unknown', $container->getParameter('msstc4symfony_metrics.component_name'));
        self::assertFalse($container->getParameter('msstc4symfony_metrics.errors.short_exception_class_name'));
        self::assertTrue($container->getParameter('msstc4symfony_metrics.http_client.sanitize_path'));
        self::assertTrue($container->getParameter('msstc4symfony_metrics.elastica.sanitize_path'));
        self::assertSame([BuiltInMetric::class], $container->getParameter('msstc4symfony_metrics.metric_enums'));
        self::assertSame([], $container->getParameter('msstc4symfony_metrics.cache.pools'));
        self::assertFalse($container->getParameter('msstc4symfony_metrics.messenger.queue_depth.enabled'));
        self::assertSame([], $container->getParameter('msstc4symfony_metrics.messenger.queue_depth.transports'));
        self::assertSame('PT1M', $container->getParameter('msstc4symfony_metrics.messenger.queue_depth.period'));
    }

    public function testEnvironmentVariablesFeedTheDefaults(): void
    {
        $_SERVER['METRICS_STORAGE_DSN'] = 'inmemory://';
        $_SERVER['APPLICATION_NAME'] = 'shop';
        $_SERVER['COMPONENT_NAME'] = 'api';

        $container = $this->bootKernel([])->getContainer();

        self::assertSame('inmemory://', $container->getParameter('msstc4symfony_metrics.storage.dsn'));
        self::assertSame('shop', $container->getParameter('msstc4symfony_metrics.application_name'));
        self::assertSame('api', $container->getParameter('msstc4symfony_metrics.component_name'));
    }

    public function testConfiguredValuesBecomeParameters(): void
    {
        $container = $this->bootKernel([
            'storage' => ['dsn' => 'inmemory://', 'reconnect_backoff_seconds' => 0],
            'application_name' => 'shop',
            'component_name' => 'worker',
            'errors' => ['short_exception_class_name' => true],
            'http_client' => ['sanitize_path' => false],
            'elastica' => ['sanitize_path' => false],
            'metric_enums' => [ExtraMetricEnum::class],
            'cache' => ['pools' => ['cache.app', 'cache.system', 'cache.app']],
            'messenger' => ['queue_depth' => ['enabled' => false, 'transports' => ['async', 'failed'], 'period' => 'PT30S']],
        ])->getContainer();

        self::assertSame('inmemory://', $container->getParameter('msstc4symfony_metrics.storage.dsn'));
        self::assertSame(0.0, $container->getParameter('msstc4symfony_metrics.storage.reconnect_backoff_seconds'));
        self::assertSame('shop', $container->getParameter('msstc4symfony_metrics.application_name'));
        self::assertSame('worker', $container->getParameter('msstc4symfony_metrics.component_name'));
        self::assertTrue($container->getParameter('msstc4symfony_metrics.errors.short_exception_class_name'));
        self::assertFalse($container->getParameter('msstc4symfony_metrics.http_client.sanitize_path'));
        self::assertFalse($container->getParameter('msstc4symfony_metrics.elastica.sanitize_path'));
        self::assertSame(
            [BuiltInMetric::class, ExtraMetricEnum::class],
            $container->getParameter('msstc4symfony_metrics.metric_enums'),
        );
        self::assertSame(['cache.app', 'cache.system'], $container->getParameter('msstc4symfony_metrics.cache.pools'));
        self::assertFalse($container->getParameter('msstc4symfony_metrics.messenger.queue_depth.enabled'));
        self::assertSame(['async', 'failed'], $container->getParameter('msstc4symfony_metrics.messenger.queue_depth.transports'));
        self::assertSame('PT30S', $container->getParameter('msstc4symfony_metrics.messenger.queue_depth.period'));
        self::assertInstanceOf(CountingCacheAdapter::class, $container->get('cache.app'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unmeasurablePoolProvider(): iterable
    {
        yield 'the bundle throttle pool' => ['metrics.cache', 'The "metrics.cache" pool cannot be listed in msstc4symfony_metrics.cache.pools: the bundle reads it on every request and worker loop to throttle its own sampling.'];
        yield 'the worker restart signal' => ['cache.messenger.restart_workers_signal', 'The "cache.messenger.restart_workers_signal" pool cannot be listed in msstc4symfony_metrics.cache.pools: every Messenger worker reads it on each loop.'];
    }

    #[DataProvider('unmeasurablePoolProvider')]
    public function testRejectsPoolsReadOnEveryLoop(string $pool, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        $this->bootKernel(['cache' => ['pools' => [$pool]]]);
    }

    public function testRejectsAnEmptyPoolName(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->bootKernel(['cache' => ['pools' => ['']]]);
    }

    public function testRejectsAQueueDepthPeriodThatIsNoInterval(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"1 minute" is not an ISO 8601 interval');

        $this->bootKernel(['messenger' => ['queue_depth' => ['period' => '1 minute']]]);
    }

    public function testRejectsAZeroQueueDepthPeriod(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"PT0S" is not an ISO 8601 interval');

        $this->bootKernel(['messenger' => ['queue_depth' => ['period' => 'PT0S']]]);
    }

    public function testQueueDepthNeedsMessenger(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('msstc4symfony_metrics.messenger.queue_depth needs Symfony Messenger (framework.messenger).');

        $this->bootKernel(['messenger' => ['queue_depth' => true]]);
    }

    public function testExtraEnumsReachTheMetricRepository(): void
    {
        $repository = $this->service(
            $this->bootKernel(['metric_enums' => [ExtraMetricEnum::class]])->getContainer(),
            MetricRepository::class,
        );

        self::assertSame(\count(BuiltInMetric::cases()) + 1, \count($repository->findAll()));
        self::assertSame(ExtraMetricEnum::EXTRA, $repository->find(ExtraMetricEnum::EXTRA)->name);
    }

    public function testRejectsEnumNotImplementingTheCatalogInterface(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(stdClass::class);

        $this->bootKernel(['metric_enums' => [stdClass::class]]);
    }

    public function testRejectsAnEnumThatIsNotStringBacked(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('IntBackedMetricEnum" is not a string-backed enum');

        $this->bootKernel(['metric_enums' => [IntBackedMetricEnum::class]]);
    }

    public function testRejectsNegativeReconnectBackoff(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->bootKernel(['storage' => ['reconnect_backoff_seconds' => -1]]);
    }

    public function testReconnectBackoffAcceptsAnEnvPlaceholder(): void
    {
        $_SERVER['METRICS_TEST_BACKOFF'] = '2.5';

        $container = $this->bootKernel(['storage' => ['reconnect_backoff_seconds' => '%env(float:METRICS_TEST_BACKOFF)%']])->getContainer();

        self::assertSame(2.5, $container->getParameter('msstc4symfony_metrics.storage.reconnect_backoff_seconds'));
    }

    public function testShortExceptionClassNameOptionReachesTheErrorCollector(): void
    {
        $container = $this->bootKernel([
            'storage' => ['dsn' => 'inmemory://'],
            'errors' => ['short_exception_class_name' => true],
        ])->getContainer();

        $this->service($container, ErrorCollector::class)->incException(new RuntimeException('boom'));

        self::assertSame(
            [['unknown', 'unknown', 'RuntimeException']],
            RegistrySamples::labels($this->service($container, RegistryInterface::class), BuiltInMetric::EXCEPTION),
        );
    }

    public function testMetricsEndpointRendersRecordedMetricsButNoMessengerSeriesWithoutMessengerConfig(): void
    {
        $kernel = $this->bootKernel(['storage' => ['dsn' => 'inmemory://']]);
        $testContainer = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $testContainer);

        // The listener follows the installed library, not framework.messenger: without that config no Messenger event is ever dispatched.
        self::assertSame(TestKernel::hasMessenger(), $testContainer->has(MessengerEventListener::class));
        $this->service($kernel->getContainer(), ErrorCollector::class)->incException(new RuntimeException('boom'));

        $response = $kernel->handle(Request::create('/_/metrics'));
        $body = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('symfony_exception{application="unknown",component="unknown",class="RuntimeException"} 1', $body);
        self::assertStringNotContainsString('messenger_', $body);
    }

    /**
     * @param MetricsConfigShape|null $config
     */
    private function bootKernel(?array $config): BundleConfigKernel
    {
        $this->kernel = new BundleConfigKernel($config);
        $this->kernel->boot();

        return $this->kernel;
    }

    /**
     * @template TService of object
     *
     * @param class-string<TService> $id
     *
     * @return TService
     */
    private function service(ContainerInterface $container, string $id): object
    {
        $testContainer = $container->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $testContainer);
        $service = $testContainer->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }
}
