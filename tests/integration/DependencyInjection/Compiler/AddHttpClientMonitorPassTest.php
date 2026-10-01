<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\DependencyInjection\Compiler;

use Msstc4Symfony\MetricsBundle\DependencyInjection\Compiler\AddHttpClientMonitorPass;
use Msstc4Symfony\MetricsBundle\Infrastructure\HttpClient\HttpClientDecorator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpClient\Response\AsyncResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AddHttpClientMonitorPassTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(AsyncResponse::class)) {
            self::markTestSkipped('symfony/http-client not installed');
        }
    }

    public function testDecoratesOnlyTheSharedTransport(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(AddHttpClientMonitorPass::TRANSPORT_ID, new Definition(HttpClientInterface::class));
        $container->setDefinition('http_client', new Definition(HttpClientInterface::class));
        $container->setDefinition('github.client', new Definition(HttpClientInterface::class));

        new AddHttpClientMonitorPass()->process($container);

        $decorator = $container->getDefinition(AddHttpClientMonitorPass::DECORATOR_ID);
        self::assertSame(HttpClientDecorator::class, $decorator->getClass());
        self::assertSame([AddHttpClientMonitorPass::TRANSPORT_ID, null, 0], $decorator->getDecoratedService());
        self::assertFalse($container->hasDefinition('http_client.decorator.monitor'));
        self::assertFalse($container->hasDefinition('github.client.decorator.monitor'));
    }

    public function testDoesNothingWithoutFrameworkHttpClient(): void
    {
        $container = new ContainerBuilder();

        new AddHttpClientMonitorPass()->process($container);

        self::assertFalse($container->hasDefinition(AddHttpClientMonitorPass::DECORATOR_ID));
    }

    public function testSkipsAbstractTransport(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(AddHttpClientMonitorPass::TRANSPORT_ID, new Definition(HttpClientInterface::class)->setAbstract(true));

        new AddHttpClientMonitorPass()->process($container);

        self::assertFalse($container->hasDefinition(AddHttpClientMonitorPass::DECORATOR_ID));
    }
}
