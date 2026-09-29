<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\DependencyInjection\Compiler;

use Msstc4Symfony\MetricsBundle\DependencyInjection\Compiler\AddHttpClientMonitorPass;
use Msstc4Symfony\MetricsBundle\Infrastructure\HttpClient\HttpClientDecorator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpClient\ScopingHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AddHttpClientMonitorPassTest extends TestCase
{
    protected function setUp(): void
    {
        if (!interface_exists(HttpClientInterface::class) || !class_exists(ScopingHttpClient::class)) {
            self::markTestSkipped('symfony/http-client not installed');
        }
    }

    public function testDecoratesHttpClientInterfaceDefinition(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('app.http_client', new Definition(HttpClientInterface::class, [['base_uri' => 'https://api.example.com']]));

        new AddHttpClientMonitorPass()->process($container);

        self::assertTrue($container->hasDefinition('app.http_client.decorator.monitor'));
        $decorator = $container->getDefinition('app.http_client.decorator.monitor');
        self::assertSame(HttpClientDecorator::class, $decorator->getClass());
        self::assertSame('https://api.example.com', $decorator->getArgument('$baseUri'));
    }

    public function testDecoratesScopingHttpClientDefinition(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('app.scoped', new Definition(ScopingHttpClient::class, [null, 'https://scoped.example.com']));

        new AddHttpClientMonitorPass()->process($container);

        $decorator = $container->getDefinition('app.scoped.decorator.monitor');
        self::assertSame('https://scoped.example.com', $decorator->getArgument('$baseUri'));
    }

    public function testSkipsAlreadyDecoratedDefinitions(): void
    {
        $container = new ContainerBuilder();
        $existing = new Definition(HttpClientInterface::class);
        $existing->setDecoratedService('some.other.id');

        $container->setDefinition('app.already_decorated', $existing);

        new AddHttpClientMonitorPass()->process($container);

        self::assertFalse($container->hasDefinition('app.already_decorated.decorator.monitor'));
    }

    public function testSkipsAbstractDefinitions(): void
    {
        $container = new ContainerBuilder();
        $abstract = new Definition(HttpClientInterface::class);
        $abstract->setAbstract(true);

        $container->setDefinition('app.abstract', $abstract);

        new AddHttpClientMonitorPass()->process($container);

        self::assertFalse($container->hasDefinition('app.abstract.decorator.monitor'));
    }
}
