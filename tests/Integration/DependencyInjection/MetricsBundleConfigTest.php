<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\DependencyInjection;

use Msstc4Symfony\MetricsBundle\Framework\EventListener\MessengerEventListener;
use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\ErrorCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepository;
use Msstc4Symfony\MetricsBundle\Test\Integration\DependencyInjection\Fixture\ExtraMetricEnum;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\BundleConfigKernel;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\TestKernel;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;
use Override;
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
    private const array ENV_VARS = ['METRICS_STORAGE_DSN', 'APPLICATION_NAME', 'COMPONENT_NAME', 'METRICS_TEST_BACKOFF'];

    private ?BundleConfigKernel $kernel = null;

    #[Override]
    protected function setUp(): void
    {
        new Filesystem()->remove(TestKernel::cacheRoot());
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

        new Filesystem()->remove(TestKernel::cacheRoot());
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
        self::assertSame([MetricLabelEnum::class], $container->getParameter('msstc4symfony_metrics.metric_enums'));
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
        ])->getContainer();

        self::assertSame('inmemory://', $container->getParameter('msstc4symfony_metrics.storage.dsn'));
        self::assertSame(0.0, $container->getParameter('msstc4symfony_metrics.storage.reconnect_backoff_seconds'));
        self::assertSame('shop', $container->getParameter('msstc4symfony_metrics.application_name'));
        self::assertSame('worker', $container->getParameter('msstc4symfony_metrics.component_name'));
        self::assertTrue($container->getParameter('msstc4symfony_metrics.errors.short_exception_class_name'));
        self::assertFalse($container->getParameter('msstc4symfony_metrics.http_client.sanitize_path'));
        self::assertFalse($container->getParameter('msstc4symfony_metrics.elastica.sanitize_path'));
        self::assertSame(
            [MetricLabelEnum::class, ExtraMetricEnum::class],
            $container->getParameter('msstc4symfony_metrics.metric_enums'),
        );
    }

    public function testExtraEnumsReachTheMetricRepository(): void
    {
        $repository = $this->service(
            $this->bootKernel(['metric_enums' => [ExtraMetricEnum::class]])->getContainer(),
            MetricRepository::class,
        );

        self::assertSame(\count(MetricLabelEnum::cases()) + 1, \count($repository->findAll()));
        self::assertSame(ExtraMetricEnum::EXTRA, $repository->find(ExtraMetricEnum::EXTRA)->name);
    }

    public function testRejectsEnumNotImplementingTheCatalogInterface(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(stdClass::class);

        $this->bootKernel(['metric_enums' => [stdClass::class]]);
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
            RegistrySamples::labels($this->service($container, RegistryInterface::class), MetricLabelEnum::EXCEPTION),
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
