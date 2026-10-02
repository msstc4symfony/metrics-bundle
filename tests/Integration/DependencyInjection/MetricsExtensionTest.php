<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\DependencyInjection;

use Msstc4Symfony\MetricsBundle\DependencyInjection\MetricsExtension;
use Msstc4Symfony\MetricsBundle\Framework\EventListener\MessengerEventListener;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepository;
use Msstc4Symfony\MetricsBundle\Infrastructure\Storage\Factory;
use PHPUnit\Framework\TestCase;
use Prometheus\RegistryInterface;
use Prometheus\RendererInterface;
use Prometheus\Storage\Adapter;
use ReflectionParameter;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Compiler\MergeExtensionConfigurationPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Yaml\Yaml;

final class MetricsExtensionTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Yaml::class)) {
            self::markTestSkipped('symfony/yaml not installed (required to load services.yaml)');
        }
    }

    public function testLoadRegistersBundleParameters(): void
    {
        $container = new ContainerBuilder();

        new MetricsExtension()->load([], $container);

        self::assertSame('redis://127.0.0.1:6379', $container->getParameter('metrics_bundle.dsnDefault'));
        self::assertSame('unknown', $container->getParameter('metrics_bundle.applicationNameDefault'));
        self::assertSame('unknown', $container->getParameter('metrics_bundle.componentNameDefault'));
        self::assertFalse($container->getParameter('metrics_bundle.exceptionLabelShortClassName'));
        self::assertTrue($container->getParameter('metrics_bundle.httpClientSanitizePath'));
        self::assertSame(
            [MetricLabelEnum::class],
            $container->getParameter('metrics_bundle.metric_enums'),
        );
    }

    public function testDoctrineConnectionLabelDefaultsToHostAndDatabaseName(): void
    {
        $container = new ContainerBuilder();

        new MetricsExtension()->load([], $container);

        self::assertSame('host_dbname', $container->getParameter(MetricsExtension::DOCTRINE_CONNECTION_LABEL_PARAMETER));
    }

    public function testDoctrineConnectionLabelCanBeTheConnectionName(): void
    {
        $container = new ContainerBuilder();

        new MetricsExtension()->load([['doctrine' => ['connection_label' => 'name']]], $container);

        self::assertSame('name', $container->getParameter(MetricsExtension::DOCTRINE_CONNECTION_LABEL_PARAMETER));
    }

    public function testDoctrineConnectionLabelRejectsUnknownValues(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        new MetricsExtension()->load([['doctrine' => ['connection_label' => 'dsn']]], new ContainerBuilder());
    }

    public function testStorageReconnectBackoffDefaultsToFiveSeconds(): void
    {
        $container = new ContainerBuilder();

        new MetricsExtension()->load([], $container);

        self::assertSame(5.0, $container->getParameter(MetricsExtension::STORAGE_RECONNECT_BACKOFF_PARAMETER));
    }

    public function testStorageReconnectBackoffCanBeDisabled(): void
    {
        $container = new ContainerBuilder();

        new MetricsExtension()->load([['storage' => ['reconnect_backoff_seconds' => 0]]], $container);

        self::assertSame(0.0, $container->getParameter(MetricsExtension::STORAGE_RECONNECT_BACKOFF_PARAMETER));
    }

    public function testStorageReconnectBackoffRejectsNegativeValues(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        new MetricsExtension()->load([['storage' => ['reconnect_backoff_seconds' => -1]]], new ContainerBuilder());
    }

    public function testStorageReconnectBackoffAcceptsAnEnvPlaceholder(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new MetricsExtension());
        $container->loadFromExtension('metrics', ['storage' => ['reconnect_backoff_seconds' => '%env(float:METRICS_TEST_BACKOFF)%']]);
        $_SERVER['METRICS_TEST_BACKOFF'] = '2.5';

        try {
            new MergeExtensionConfigurationPass()->process($container);
            $resolved = $container->resolveEnvPlaceholders($container->getParameter(MetricsExtension::STORAGE_RECONNECT_BACKOFF_PARAMETER), true);
        } finally {
            unset($_SERVER['METRICS_TEST_BACKOFF']);
        }

        self::assertSame(2.5, $resolved);
    }

    public function testFactoryIsWiredToTheBackoffParameter(): void
    {
        $parameter = new ReflectionParameter([Factory::class, '__construct'], 'reconnectBackoffSeconds');
        $autowire = $parameter->getAttributes(Autowire::class)[0] ?? null;

        self::assertNotNull($autowire);
        self::assertSame('%' . MetricsExtension::STORAGE_RECONNECT_BACKOFF_PARAMETER . '%', $autowire->newInstance()->value);
    }

    public function testMessengerListenerIsRegisteredOnlyWithMessenger(): void
    {
        $container = new ContainerBuilder();

        new MetricsExtension()->load([], $container);

        // Paths excluded from the resource scan still get a definition, tagged "container.excluded".
        self::assertSame(
            class_exists(WorkerMessageReceivedEvent::class),
            $container->hasDefinition(MessengerEventListener::class)
                && !$container->getDefinition(MessengerEventListener::class)->hasTag('container.excluded'),
        );
    }

    public function testLoadRegistersDefaultServices(): void
    {
        $container = new ContainerBuilder();

        new MetricsExtension()->load([], $container);

        self::assertTrue($container->hasDefinition(Adapter::class));
        self::assertTrue($container->hasDefinition(RegistryInterface::class));
        self::assertTrue($container->hasDefinition(RendererInterface::class));
        self::assertTrue($container->hasDefinition(MetricRepository::class));
    }
}
