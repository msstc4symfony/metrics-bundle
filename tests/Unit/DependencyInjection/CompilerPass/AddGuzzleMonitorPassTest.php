<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\DependencyInjection\CompilerPass;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Msstc4Symfony\MetricsBundle\Application\Collector\ExternalConnectionCollector;
use Msstc4Symfony\MetricsBundle\Application\Port\MetricRecorderInterface;
use Msstc4Symfony\MetricsBundle\DependencyInjection\CompilerPass\AddGuzzleMonitorPass;
use Msstc4Symfony\MetricsBundle\Infrastructure\Guzzle\ChainedClientConfigurator;
use Msstc4Symfony\MetricsBundle\Infrastructure\Guzzle\MetricsGuzzleMiddleware;
use Msstc4Symfony\MetricsBundle\Infrastructure\OutgoingHttp\OutgoingRequestLabels;
use Msstc4Symfony\MetricsBundle\Test\Support\Guzzle\ClientWithoutConfig;
use Msstc4Symfony\MetricsBundle\Test\Support\Guzzle\CustomConstructorClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

#[RequiresMethod(ClientInterface::class, 'send')]
final class AddGuzzleMonitorPassTest extends TestCase
{
    public function testWrapsTheHandlerOfGuzzleClientsWithoutTouchingOthers(): void
    {
        $container = $this->container();
        $container->setDefinition('github', new Definition(Client::class));
        $container->setDefinition('abstract', (new Definition(Client::class))->setAbstract(true));
        $container->setDefinition('other', new Definition(stdClass::class));
        $container->setDefinition('no class', new Definition());

        (new AddGuzzleMonitorPass())->process($container);

        self::assertEquals([['handler' => $this->measuredHandler(null)]], $container->getDefinition('github')->getArguments());
        self::assertNull($container->getDefinition('github')->getConfigurator());
        self::assertSame([], $container->getDefinition('abstract')->getArguments());
        self::assertSame([], $container->getDefinition('other')->getArguments());
    }

    public function testKeepsTheClientConfigAndItsHandler(): void
    {
        $container = $this->container();
        $container->setDefinition('app.stack', new Definition(HandlerStack::class));
        $container->setDefinition('github', new Definition(Client::class, [['handler' => new Reference('app.stack'), 'timeout' => 3]]));

        (new AddGuzzleMonitorPass())->process($container);

        self::assertEquals(
            [['handler' => $this->measuredHandler(new Reference('app.stack')), 'timeout' => 3]],
            $container->getDefinition('github')->getArguments(),
        );
    }

    public function testMeasuresANamedConfigArgumentOnceTheContainerResolvedIt(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(MetricRecorderInterface::class, (new Definition(MetricRecorderInterface::class))->setSynthetic(true));
        $container->setDefinition(ExternalConnectionCollector::class, new Definition(ExternalConnectionCollector::class, [new Reference(MetricRecorderInterface::class)]));
        $container->setDefinition(OutgoingRequestLabels::class, new Definition(OutgoingRequestLabels::class, [[], true]));
        $container->setDefinition(MetricsGuzzleMiddleware::class, (new Definition(MetricsGuzzleMiddleware::class))->setAutowired(true));
        $container->setDefinition('github', (new Definition(Client::class, ['$config' => ['timeout' => 3]]))->setPublic(true));
        $container->addCompilerPass(new AddGuzzleMonitorPass(), PassConfig::TYPE_BEFORE_REMOVING);

        $container->compile(true);

        $config = $container->getDefinition('github')->getArguments()[0] ?? null;
        self::assertIsArray($config);
        self::assertSame(3, $config['timeout'] ?? null);
        $handler = $config['handler'] ?? null;
        self::assertInstanceOf(Definition::class, $handler);
        self::assertSame(HandlerStack::class, $handler->getClass());
        $factory = $handler->getFactory();
        self::assertIsArray($factory);
        self::assertSame('decorateHandler', $factory[1]);
        // The middleware is used once, so the container inlines it.
        self::assertInstanceOf(Definition::class, $factory[0]);
        self::assertSame(MetricsGuzzleMiddleware::class, $factory[0]->getClass());
    }

    public function testMeasuresAClientWithItsOwnConfiguratorAndKeepsIt(): void
    {
        $container = $this->container();
        $container->setDefinition('own', (new Definition(Client::class))->setConfigurator(['app', 'configure']));

        (new AddGuzzleMonitorPass())->process($container);

        self::assertSame(['app', 'configure'], $container->getDefinition('own')->getConfigurator());
        self::assertEquals([['handler' => $this->measuredHandler(null)]], $container->getDefinition('own')->getArguments());
    }

    /**
     * @return iterable<string, array{string, int|string}>
     */
    public static function defaultHandlerOptions(): iterable
    {
        yield 'max_host_connections' => ['max_host_connections', 4];
        yield 'max_total_connections' => ['max_total_connections', 16];
        yield 'transport_sharing' => ['transport_sharing', 'none'];
        yield 'multiplex' => ['multiplex', 'none'];
    }

    #[DataProvider('defaultHandlerOptions')]
    public function testLetsGuzzleBuildTheDefaultHandlerWhenTheConfigTunesIt(string $option, int|string $value): void
    {
        $container = $this->container();
        $container->setDefinition('github', new Definition(Client::class, [[$option => $value]]));

        (new AddGuzzleMonitorPass())->process($container);

        self::assertSame([[$option => $value]], $container->getDefinition('github')->getArguments());
        self::assertEquals([new Reference(MetricsGuzzleMiddleware::class), 'addHandler'], $container->getDefinition('github')->getConfigurator());
    }

    public function testResolvesParameterisedClasses(): void
    {
        $container = $this->container();
        $container->setParameter('app.client.class', Client::class);
        $container->setDefinition('github', new Definition('%app.client.class%'));

        (new AddGuzzleMonitorPass())->process($container);

        self::assertCount(1, $container->getDefinition('github')->getArguments());
    }

    public function testConfiguresAClientBuiltByAFactory(): void
    {
        $container = $this->container();
        $container->setDefinition('built', (new Definition(Client::class, ['token']))->setFactory(['app.factory', 'create']));

        (new AddGuzzleMonitorPass())->process($container);

        self::assertSame(['token'], $container->getDefinition('built')->getArguments());
        self::assertEquals([new Reference(MetricsGuzzleMiddleware::class), 'addHandler'], $container->getDefinition('built')->getConfigurator());
    }

    public function testChainsTheOwnConfiguratorOfAClientBuiltByAFactory(): void
    {
        $container = $this->container();
        $container->setDefinition('built', (new Definition(Client::class))->setFactory(['app.factory', 'create'])->setConfigurator(['app', 'configure']));

        (new AddGuzzleMonitorPass())->process($container);

        $configurator = $container->getDefinition('built')->getConfigurator();
        self::assertIsArray($configurator);
        self::assertSame('__invoke', $configurator[1]);
        self::assertInstanceOf(Definition::class, $configurator[0]);
        self::assertSame(ChainedClientConfigurator::class, $configurator[0]->getClass());
        self::assertEquals([['app', 'configure'], new Reference(MetricsGuzzleMiddleware::class)], $configurator[0]->getArguments());
    }

    public function testConfiguresAClientWhoseConfigIsAParameter(): void
    {
        $container = $this->container();
        $container->setDefinition('github', new Definition(Client::class, ['%app.guzzle_config%']));

        (new AddGuzzleMonitorPass())->process($container);

        self::assertSame(['%app.guzzle_config%'], $container->getDefinition('github')->getArguments());
        self::assertEquals([new Reference(MetricsGuzzleMiddleware::class), 'addHandler'], $container->getDefinition('github')->getConfigurator());
    }

    public function testConfiguresAClientWithItsOwnConstructor(): void
    {
        $container = $this->container();
        $container->setDefinition('custom', new Definition(CustomConstructorClient::class, ['token']));

        (new AddGuzzleMonitorPass())->process($container);

        self::assertSame(['token'], $container->getDefinition('custom')->getArguments());
        self::assertEquals([new Reference(MetricsGuzzleMiddleware::class), 'addHandler'], $container->getDefinition('custom')->getConfigurator());
    }

    public function testLeavesAClientItCannotReachAndLogsIt(): void
    {
        if (method_exists(ClientInterface::class, 'getConfig')) {
            self::markTestSkipped('Guzzle 7 clients always have getConfig()');
        }

        $container = $this->container();
        $container->setDefinition('custom', (new Definition(ClientWithoutConfig::class))->setConfigurator(['app', 'configure']));
        $container->setDefinition('last', new Definition(Client::class));

        (new AddGuzzleMonitorPass())->process($container);

        self::assertSame(['app', 'configure'], $container->getDefinition('custom')->getConfigurator());
        self::assertSame(
            [AddGuzzleMonitorPass::class . ': Guzzle client "custom" is not measured: its handler cannot be set from an array config of ' . Client::class
                . '::__construct() and it has no getConfig(); configure it as a ' . Client::class . ' with a literal config array or a ' . HandlerStack::class . ' handler.'],
            $container->getCompiler()->getLog(),
        );
        self::assertEquals([['handler' => $this->measuredHandler(null)]], $container->getDefinition('last')->getArguments());
    }

    public function testHandlesEveryClientWhateverComesBefore(): void
    {
        $container = $this->container();
        $container->setDefinition('abstract', (new Definition(Client::class))->setAbstract(true));
        $container->setDefinition('other', new Definition(stdClass::class));
        $container->setDefinition('github', new Definition(Client::class));
        $container->setDefinition('custom', new Definition(CustomConstructorClient::class, ['token']));
        $container->setDefinition('last', new Definition(Client::class));

        (new AddGuzzleMonitorPass())->process($container);

        self::assertCount(1, $container->getDefinition('github')->getArguments());
        self::assertNotNull($container->getDefinition('custom')->getConfigurator());
        self::assertEquals([['handler' => $this->measuredHandler(null)]], $container->getDefinition('last')->getArguments());
    }

    public function testLeavesClientsAloneWithoutTheMiddlewareService(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('github', new Definition(Client::class));

        (new AddGuzzleMonitorPass())->process($container);

        self::assertSame([], $container->getDefinition('github')->getArguments());
    }

    /**
     * @return iterable<string, array{Reference|Definition|string}>
     */
    public static function bareHandlers(): iterable
    {
        yield 'callable name' => ['app_handler_function'];
        yield 'service of another class' => [new Reference('app.mock_handler')];
        yield 'inline definition of another class' => [new Definition(MockHandler::class)];
    }

    /**
     * @return iterable<string, array{Reference|Definition}>
     */
    public static function handlersOfUnknownType(): iterable
    {
        yield 'missing service' => [new Reference('app.missing')];
        yield 'service without class' => [new Reference('app.factory_stack')];
        yield 'inline definition without class' => [new Definition()];
        yield 'inline definition of a missing class' => [new Definition('Not\\Installed\\Handler')];
    }

    #[DataProvider('handlersOfUnknownType')]
    public function testAHandlerOfUnknownTypeIsLeftToTheConfigurator(Reference|Definition $handler): void
    {
        $container = $this->container();
        $container->setDefinition('app.factory_stack', (new Definition())->setFactory(['app.stacks', 'create']));
        $container->setDefinition('github', new Definition(Client::class, [['handler' => $handler]]));

        (new AddGuzzleMonitorPass())->process($container);

        self::assertSame([['handler' => $handler]], $container->getDefinition('github')->getArguments());
        self::assertEquals([new Reference(MetricsGuzzleMiddleware::class), 'addHandler'], $container->getDefinition('github')->getConfigurator());
        self::assertSame([], $container->getCompiler()->getLog());
    }

    #[DataProvider('bareHandlers')]
    public function testLeavesAClientWithABareHandlerAloneAndLogsIt(Reference|Definition|string $handler): void
    {
        $container = $this->container();
        $container->setDefinition('app.mock_handler', new Definition(MockHandler::class));
        $container->setDefinition('github', new Definition(Client::class, [['handler' => $handler]]));
        $container->setDefinition('last', new Definition(Client::class));

        (new AddGuzzleMonitorPass())->process($container);

        self::assertSame([['handler' => $handler]], $container->getDefinition('github')->getArguments());
        self::assertNull($container->getDefinition('github')->getConfigurator());
        self::assertSame(
            [AddGuzzleMonitorPass::class . ': Guzzle client "github" is not measured: its "handler" is not a ' . HandlerStack::class . '.'],
            $container->getCompiler()->getLog(),
        );
        self::assertEquals([['handler' => $this->measuredHandler(null)]], $container->getDefinition('last')->getArguments());
    }

    public function testMeasuresAHandlerStackServiceBehindAnAlias(): void
    {
        $container = $this->container();
        $container->setDefinition('app.stack', (new Definition(HandlerStack::class))->setFactory([HandlerStack::class, 'create']));
        $container->setAlias('app.stack_alias', 'app.stack');
        $container->setDefinition('github', new Definition(Client::class, [['handler' => new Reference('app.stack_alias')]]));

        (new AddGuzzleMonitorPass())->process($container);

        self::assertEquals([['handler' => $this->measuredHandler(new Reference('app.stack_alias'))]], $container->getDefinition('github')->getArguments());
        self::assertSame([], $container->getCompiler()->getLog());
    }

    public function testSurvivesClassesWithAMissingParent(): void
    {
        // Generated at run time: a source file extending a missing class would fail static analysis.
        $class = 'Msstc4Symfony\\MetricsBundle\\Test\\Generated\\OrphanClient';
        $file = sys_get_temp_dir() . '/msstc4symfony-metrics-orphan-client-' . getmypid() . '.php';
        file_put_contents($file, "<?php\nnamespace Msstc4Symfony\\MetricsBundle\\Test\\Generated;\nfinal class OrphanClient extends \\Not\\Installed\\BaseClient {}\n");
        $autoload = static function (string $name) use ($class, $file): void {
            if ($name === $class) {
                require $file;
            }
        };
        spl_autoload_register($autoload);

        try {
            $container = $this->container();
            $container->setDefinition('orphan', new Definition($class));
            $container->setDefinition('orphan_handler', new Definition(Client::class, [['handler' => new Definition($class)]]));

            (new AddGuzzleMonitorPass())->process($container);

            self::assertNull($container->getDefinition('orphan')->getConfigurator());
            self::assertSame([], $container->getDefinition('orphan')->getArguments());
            self::assertEquals([['handler' => new Definition($class)]], $container->getDefinition('orphan_handler')->getArguments());
        } finally {
            spl_autoload_unregister($autoload);
            unlink($file);
        }
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition(MetricsGuzzleMiddleware::class, new Definition(MetricsGuzzleMiddleware::class));

        return $container;
    }

    private function measuredHandler(?Reference $handler): Definition
    {
        return (new Definition(HandlerStack::class, [$handler]))
            ->setFactory([new Reference(MetricsGuzzleMiddleware::class), 'decorateHandler'])
        ;
    }
}
