<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Test\Integration\DependencyInjection;

use MaxShamaev\MetricsBundle\DependencyInjection\MetricsExtension;
use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use MaxShamaev\MetricsBundle\Infrastructure\Repository\MetricRepository;
use PHPUnit\Framework\TestCase;
use Prometheus\RegistryInterface;
use Prometheus\RendererInterface;
use Prometheus\Storage\Adapter;
use Symfony\Component\DependencyInjection\ContainerBuilder;
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
