<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\DependencyInjection\Compiler;

use Elastic\Transport\Transport;
use Elastica\Client;
use Msstc4Symfony\MetricsBundle\DependencyInjection\Compiler\DecorateElasticaClientsPass;
use Msstc4Symfony\MetricsBundle\DependencyInjection\Compiler\SaveElasticaClientsListPass;
use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\ElasticaCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Elastica\ConfiguredHttpClientFactory;
use Msstc4Symfony\MetricsBundle\Infrastructure\Elastica\TimingHttpClient;
use Msstc4Symfony\MetricsBundle\Test\Support\SubclassedElasticaClient;
use Override;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\Argument\AbstractArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\ResolveChildDefinitionsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class DecorateElasticaClientsPassTest extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        if (!class_exists(Transport::class) || !class_exists(Client::class)) {
            self::markTestSkipped('ruflin/elastica 8 not installed');
        }
    }

    public function testInjectsTheTimingClientIntoAnArrayConfig(): void
    {
        $container = $this->container(new Definition(Client::class, [['hosts' => ['http://es:9200']]]));

        new DecorateElasticaClientsPass()->process($container);

        $http = $this->httpClient($container);
        self::assertSame(TimingHttpClient::class, $http->getClass());
        $inner = $http->getArgument(0);
        self::assertInstanceOf(Definition::class, $inner);
        self::assertSame([ConfiguredHttpClientFactory::class, 'create'], $inner->getFactory());
        self::assertSame([null, null, null], $inner->getArguments());
        self::assertEquals(new Reference(ElasticaCollector::class), $http->getArgument(1));
        self::assertSame(['http://es:9200'], $this->config($container)['hosts'] ?? null);
    }

    public function testHandsTheSanitizePathParameterToTheTimingClient(): void
    {
        $container = $this->container(new Definition(Client::class, [['hosts' => ['http://es:9200']]]));
        $container->setParameter(DecorateElasticaClientsPass::SANITIZE_PATH_PARAMETER, false);

        new DecorateElasticaClientsPass()->process($container);

        $http = $this->httpClient($container);
        self::assertSame('%' . DecorateElasticaClientsPass::SANITIZE_PATH_PARAMETER . '%', $http->getArgument(2));
        self::assertFalse($container->getParameterBag()->resolveValue($http->getArgument(2)));
    }

    public function testKeepsTheTimingClientDefaultWithoutTheSanitizePathParameter(): void
    {
        $container = $this->container(new Definition(Client::class, [['hosts' => ['http://es:9200']]]));

        new DecorateElasticaClientsPass()->process($container);

        self::assertCount(2, $this->httpClient($container)->getArguments());
    }

    public function testWrapsTheConfiguredHttpClient(): void
    {
        $container = $this->container(new Definition(Client::class, [[
            'hosts' => ['http://es:9200'],
            'transport_config' => ['http_client' => new Reference('app.psr18'), 'node_pool' => 'kept'],
        ]]));

        new DecorateElasticaClientsPass()->process($container);

        self::assertEquals(new Reference('app.psr18'), $this->innerClient($this->httpClient($container))->getArgument(0));
        $transportConfig = $this->config($container)['transport_config'] ?? null;
        self::assertIsArray($transportConfig);
        self::assertSame('kept', $transportConfig['node_pool'] ?? null);
    }

    public function testDecoratesSubclassClients(): void
    {
        $container = $this->container(new Definition(SubclassedElasticaClient::class, [['hosts' => ['http://es:9200']]]));

        new DecorateElasticaClientsPass()->process($container);

        self::assertSame(TimingHttpClient::class, $this->httpClient($container)->getClass());
    }

    public function testDecoratesAClientWithoutArguments(): void
    {
        $container = $this->container(new Definition(Client::class));

        new DecorateElasticaClientsPass()->process($container);

        self::assertSame(TimingHttpClient::class, $this->httpClient($container)->getClass());
    }

    public function testDecoratesANamedConfigArgument(): void
    {
        $container = $this->container(new Definition(Client::class, ['$config' => ['hosts' => ['http://es:9200']]]));

        new DecorateElasticaClientsPass()->process($container);

        $arguments = $container->getDefinition('app.elastica')->getArguments();
        self::assertSame(['$config'], array_keys($arguments));
        self::assertIsArray($arguments['$config']);
        $transportConfig = $arguments['$config']['transport_config'] ?? null;
        self::assertIsArray($transportConfig);
        $http = $transportConfig['http_client'] ?? null;
        self::assertInstanceOf(Definition::class, $http);
        self::assertSame(TimingHttpClient::class, $http->getClass());
    }

    public function testSkipsClientsWhoseConfigIsNotALiteralArray(): void
    {
        $container = $this->container(new Definition(Client::class, ['http://es:9200']));

        new DecorateElasticaClientsPass()->process($container);

        self::assertSame('http://es:9200', $container->getDefinition('app.elastica')->getArgument(0));
        self::assertStringContainsString('Elastica client "app.elastica" is not measured', $this->compilerLog($container));
    }

    public function testHandsClientOptionsToTheFactoryAndRemovesThemFromTheTransportConfig(): void
    {
        $container = $this->container(new Definition(Client::class, [[
            'hosts' => ['http://es:9200'],
            'transport_config' => [
                'http_client' => new Reference('app.psr18'),
                'http_client_config' => ['ssl_verify' => false],
                'http_client_options' => ['timeout' => 1],
            ],
        ]]));

        new DecorateElasticaClientsPass()->process($container);

        $inner = $this->innerClient($this->httpClient($container));
        self::assertEquals([new Reference('app.psr18'), ['ssl_verify' => false], ['timeout' => 1]], $inner->getArguments());
        $transportConfig = $this->config($container)['transport_config'] ?? null;
        self::assertIsArray($transportConfig);
        self::assertSame(['http_client'], array_keys($transportConfig));
    }

    public function testDecoratesAFosElasticaBundle7Client(): void
    {
        $prototype = new Definition(SubclassedElasticaClient::class, [
            '$config' => new AbstractArgument('configuration for Ruflin Client'),
            '$forbiddenCodes' => new AbstractArgument('list of forbidden codes for Client'),
            '$logger' => new AbstractArgument('logger for Ruflin Client'),
        ]);
        $prototype->setAbstract(true);

        $client = new ChildDefinition('fos_elastica.client_prototype');
        $client->replaceArgument('$config', [
            'hosts' => ['http://es:9200'],
            'retries' => null,
            'transport_config' => [
                'http_client' => null,
                'http_client_config' => [],
                'http_client_options' => ['headers' => [], 'timeout' => null],
                'node_pool' => null,
            ],
        ]);
        $client->replaceArgument('$forbiddenCodes', [400, 403, 404]);
        $client->replaceArgument('$logger', null);

        $container = $this->container($client);
        $container->setDefinition('fos_elastica.client_prototype', $prototype);

        new DecorateElasticaClientsPass()->process($container);
        new ResolveChildDefinitionsPass()->process($container);

        $resolved = $container->getDefinition('app.elastica');
        self::assertSame(SubclassedElasticaClient::class, $resolved->getClass());
        self::assertSame(['$config', '$forbiddenCodes', '$logger'], array_keys($resolved->getArguments()));
        $http = $this->httpClient($container, '$config');
        self::assertSame(TimingHttpClient::class, $http->getClass());
        self::assertEquals([null, [], ['headers' => [], 'timeout' => null]], $this->innerClient($http)->getArguments());
        self::assertSame([400, 403, 404], $resolved->getArgument('$forbiddenCodes'));
    }

    public function testDecoratesAPositionalChildDefinitionWithoutAppendingAnArgument(): void
    {
        $prototype = new Definition(SubclassedElasticaClient::class, [[], null]);
        $prototype->setAbstract(true);

        $client = new ChildDefinition('app.elastica_prototype');
        $client->replaceArgument(0, ['hosts' => ['http://es:9200']]);
        $client->replaceArgument(1, null);

        $container = $this->container($client);
        $container->setDefinition('app.elastica_prototype', $prototype);

        new DecorateElasticaClientsPass()->process($container);
        new ResolveChildDefinitionsPass()->process($container);

        self::assertCount(2, $container->getDefinition('app.elastica')->getArguments());
        self::assertSame(TimingHttpClient::class, $this->httpClient($container)->getClass());
    }

    public function testDecoratesAChildDefinitionThatInheritsItsConfig(): void
    {
        $prototype = new Definition(Client::class, [['hosts' => ['http://es:9200']]]);
        $prototype->setAbstract(true);

        $container = $this->container(new ChildDefinition('app.elastica_prototype'));
        $container->setDefinition('app.elastica_prototype', $prototype);

        new DecorateElasticaClientsPass()->process($container);

        self::assertSame(['hosts' => ['http://es:9200']], $prototype->getArgument(0));
        new ResolveChildDefinitionsPass()->process($container);
        self::assertSame(TimingHttpClient::class, $this->httpClient($container)->getClass());
    }

    public function testSkipsClientsWhoseTransportConfigIsNotALiteralArray(): void
    {
        $config = ['hosts' => ['http://es:9200'], 'transport_config' => '%app.transport%'];
        $container = $this->container(new Definition(Client::class, [$config]));

        new DecorateElasticaClientsPass()->process($container);

        self::assertSame($config, $container->getDefinition('app.elastica')->getArgument(0));
        self::assertStringContainsString('Elastica client "app.elastica" is not measured', $this->compilerLog($container));
    }

    public function testLeavesOtherServicesAlone(): void
    {
        $container = $this->container(new Definition(Client::class, [['hosts' => ['http://es:9200']]]));
        $container->setDefinition('app.other', new Definition(stdClass::class, [['hosts' => ['x']]]));

        new DecorateElasticaClientsPass()->process($container);

        self::assertSame([['hosts' => ['x']]], $container->getDefinition('app.other')->getArguments());
    }

    public function testSevenPassRecordsNothingOnElasticaEight(): void
    {
        $container = $this->container(new Definition(Client::class, [['hosts' => ['http://es:9200']]]));

        new SaveElasticaClientsListPass()->process($container);

        self::assertSame([], $container->getParameter(SaveElasticaClientsListPass::PARAMETER));
    }

    private function container(Definition $client): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition('app.elastica', $client);
        $container->setDefinition(ElasticaCollector::class, new Definition(ElasticaCollector::class));

        return $container;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function config(ContainerBuilder $container, int|string $key = 0): array
    {
        $config = $container->getDefinition('app.elastica')->getArgument($key);
        self::assertIsArray($config);

        return $config;
    }

    private function httpClient(ContainerBuilder $container, int|string $key = 0): Definition
    {
        $transportConfig = $this->config($container, $key)['transport_config'] ?? null;
        self::assertIsArray($transportConfig);
        $http = $transportConfig['http_client'] ?? null;
        self::assertInstanceOf(Definition::class, $http);

        return $http;
    }

    private function compilerLog(ContainerBuilder $container): string
    {
        return implode("\n", array_filter($container->getCompiler()->getLog(), is_string(...)));
    }

    private function innerClient(Definition $http): Definition
    {
        $inner = $http->getArgument(0);
        self::assertInstanceOf(Definition::class, $inner);
        self::assertSame([ConfiguredHttpClientFactory::class, 'create'], $inner->getFactory());

        return $inner;
    }
}
