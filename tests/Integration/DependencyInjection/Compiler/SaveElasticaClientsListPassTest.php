<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\DependencyInjection\Compiler;

use Elastica\Client;
use Elastica\Transport\AbstractTransport;
use Msstc4Symfony\MetricsBundle\DependencyInjection\Compiler\SaveElasticaClientsListPass;
use Msstc4Symfony\MetricsBundle\Test\Support\SubclassedElasticaClient;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class SaveElasticaClientsListPassTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(AbstractTransport::class)) {
            self::markTestSkipped('ruflin/elastica 7 not installed');
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
            $container->getParameter(SaveElasticaClientsListPass::PARAMETER),
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

        self::assertSame([], $container->getParameter(SaveElasticaClientsListPass::PARAMETER));
    }

    public function testSkipsAbstractDefinitions(): void
    {
        $container = new ContainerBuilder();
        $abstract = new Definition(Client::class);
        $abstract->setAbstract(true);

        $container->setDefinition('elastica.client.abstract', $abstract);

        new SaveElasticaClientsListPass()->process($container);

        self::assertSame([], $container->getParameter(SaveElasticaClientsListPass::PARAMETER));
    }

    public function testCollectsSubclassClients(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('fos_elastica.client.default', new Definition(SubclassedElasticaClient::class));

        new SaveElasticaClientsListPass()->process($container);

        self::assertSame(['fos_elastica.client.default'], $container->getParameter(SaveElasticaClientsListPass::PARAMETER));
        self::assertTrue($container->getDefinition('fos_elastica.client.default')->isPublic());
    }

    public function testCollectsAFosElasticaBundle6Client(): void
    {
        $prototype = new Definition(SubclassedElasticaClient::class, [[], null]);
        $prototype->setAbstract(true);

        $client = new ChildDefinition('fos_elastica.client_prototype');
        $client->replaceArgument(0, ['connections' => [['host' => 'es', 'port' => 9200]]]);
        $client->replaceArgument(1, null);

        $container = new ContainerBuilder();
        $container->setDefinition('fos_elastica.client_prototype', $prototype);
        $container->setDefinition('fos_elastica.client.default', $client);

        new SaveElasticaClientsListPass()->process($container);

        self::assertSame(['fos_elastica.client.default'], $container->getParameter(SaveElasticaClientsListPass::PARAMETER));
        self::assertTrue($client->isPublic());
    }
}
