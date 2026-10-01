<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\DependencyInjection\Compiler;

use Elastica\Client;
use Msstc4Symfony\MetricsBundle\DependencyInjection\Compiler\SaveElasticaClientsListPass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class SaveElasticaClientsListPassTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Client::class)) {
            self::markTestSkipped('ruflin/elastica not installed');
        }
    }

    public function testCollectsElasticaClientServiceIds(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('elastica.client.main', new Definition(Client::class));
        $container->setDefinition('elastica.client.search', new Definition(Client::class));
        $container->setDefinition('app.unrelated', new Definition(stdClass::class));

        new SaveElasticaClientsListPass()->process($container);

        self::assertSame(
            ['elastica.client.main', 'elastica.client.search'],
            $container->getParameter('metrics.elastica.clients'),
        );
    }

    public function testMakesElasticaClientDefinitionsPublic(): void
    {
        $container = new ContainerBuilder();
        $definition = new Definition(Client::class);
        $definition->setPublic(false);

        $container->setDefinition('elastica.client.main', $definition);

        new SaveElasticaClientsListPass()->process($container);

        self::assertTrue($container->getDefinition('elastica.client.main')->isPublic());
    }

    public function testEmptyParameterWhenNoClientsRegistered(): void
    {
        $container = new ContainerBuilder();

        new SaveElasticaClientsListPass()->process($container);

        self::assertSame([], $container->getParameter('metrics.elastica.clients'));
    }

    public function testSkipsAbstractDefinitions(): void
    {
        $container = new ContainerBuilder();
        $abstract = new Definition(Client::class);
        $abstract->setAbstract(true);

        $container->setDefinition('elastica.client.abstract', $abstract);

        new SaveElasticaClientsListPass()->process($container);

        self::assertSame([], $container->getParameter('metrics.elastica.clients'));
    }
}
