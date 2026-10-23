<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\DependencyInjection\CompilerPass;

use InvalidArgumentException;
use Msstc4Symfony\MetricsBundle\Application\Collector\CacheCollector;
use Msstc4Symfony\MetricsBundle\DependencyInjection\CompilerPass\DecorateCachePoolsPass;
use Msstc4Symfony\MetricsBundle\Infrastructure\Cache\ComputationCounter;
use Msstc4Symfony\MetricsBundle\Infrastructure\Cache\CountingCacheAdapter;
use Msstc4Symfony\MetricsBundle\Infrastructure\Cache\CountingCallbackWrapper;
use Msstc4Symfony\MetricsBundle\Infrastructure\Cache\CountingTagAwareCacheAdapter;
use Msstc4Symfony\MetricsBundle\Presentation\EventListener\CacheMetricsFlushListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Cache\Adapter\AbstractAdapter;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\ChainAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\ProxyAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Messenger\EarlyExpirationDispatcher;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

final class DecorateCachePoolsPassTest extends TestCase
{
    public function testDecoratesEachListedPoolWithItsName(): void
    {
        $container = $this->container(['app.users']);
        $container->setDefinition('app.users', new Definition(ArrayAdapter::class));
        $container->setDefinition('app.other', new Definition(ArrayAdapter::class));

        (new DecorateCachePoolsPass())->process($container);

        $decorator = $container->getDefinition(DecorateCachePoolsPass::DECORATOR_PREFIX . 'app.users');
        self::assertSame(CountingCacheAdapter::class, $decorator->getClass());
        self::assertSame(['app.users', null, 0], $decorator->getDecoratedService());
        self::assertEquals(
            [new Reference(DecorateCachePoolsPass::DECORATOR_PREFIX . 'app.users.inner'), 'app.users', new Reference(CacheCollector::class)],
            $decorator->getArguments(),
        );
        self::assertFalse($container->hasDefinition(DecorateCachePoolsPass::DECORATOR_PREFIX . 'app.other'));
    }

    public function testAPoolThatComputesThroughACallbackWrapperCountsThere(): void
    {
        $container = $this->container(['app.files']);
        $container->setDefinition('app.files', (new Definition(FilesystemAdapter::class))->addMethodCall('setLogger', [new Reference('logger')]));

        (new DecorateCachePoolsPass())->process($container);

        $decoratorId = DecorateCachePoolsPass::DECORATOR_PREFIX . 'app.files';
        $counter = new Reference($decoratorId . '.computations');
        self::assertSame(ComputationCounter::class, $container->getDefinition((string) $counter)->getClass());
        self::assertEquals($counter, $container->getDefinition($decoratorId)->getArgument(3));
        self::assertEquals(
            [
                ['setLogger', [new Reference('logger')]],
                ['setCallbackWrapper', [new Definition(CountingCallbackWrapper::class, [
                    (new Definition('callable'))->setFactory([new Reference($decoratorId . '.inner'), 'setCallbackWrapper'])->addArgument(null),
                    $counter,
                ])]],
            ],
            $container->getDefinition('app.files')->getMethodCalls(),
        );
    }

    /**
     * @return iterable<string, array{class-string<AdapterInterface>}>
     */
    public static function callbackWrapperPoolProvider(): iterable
    {
        yield 'AbstractAdapter subclass' => [FilesystemAdapter::class];
        yield 'TagAwareAdapter' => [TagAwareAdapter::class];
        yield 'ChainAdapter' => [ChainAdapter::class];
    }

    /**
     * @param class-string<AdapterInterface> $class
     */
    #[DataProvider('callbackWrapperPoolProvider')]
    public function testEachPoolWithAnOwnCallbackWrapperGetsAComputationCounter(string $class): void
    {
        $container = $this->container(['app.pool']);
        $container->setDefinition('app.pool', new Definition($class));

        (new DecorateCachePoolsPass())->process($container);

        self::assertTrue($container->hasDefinition(DecorateCachePoolsPass::DECORATOR_PREFIX . 'app.pool.computations'));
        self::assertSame(['setCallbackWrapper'], array_column($container->getDefinition('app.pool')->getMethodCalls(), 0));
    }

    /**
     * @return iterable<string, array{class-string<AdapterInterface>}>
     */
    public static function delegatingPoolProvider(): iterable
    {
        yield 'ArrayAdapter' => [ArrayAdapter::class];
        yield 'ProxyAdapter' => [ProxyAdapter::class];
    }

    /**
     * @param class-string<AdapterInterface> $class
     */
    #[DataProvider('delegatingPoolProvider')]
    public function testAPoolWithoutAnOwnCallbackWrapperGetsNoComputationCounter(string $class): void
    {
        $container = $this->container(['app.pool']);
        $container->setDefinition('app.pool', new Definition($class));

        (new DecorateCachePoolsPass())->process($container);

        self::assertFalse($container->hasDefinition(DecorateCachePoolsPass::DECORATOR_PREFIX . 'app.pool.computations'));
        self::assertSame([], $container->getDefinition('app.pool')->getMethodCalls());
    }

    public function testTheCountingWrapperGoesInsideTheEarlyExpirationDispatcher(): void
    {
        $container = $this->container(['app.files']);
        $previous = (new Definition('callable'))->setFactory([new Reference('app.files'), 'setCallbackWrapper'])->addArgument(null);
        $dispatcher = new Definition(EarlyExpirationDispatcher::class, [new Reference('bus'), new Reference('reverse_container'), $previous]);
        $container->setDefinition('app.files', (new Definition(FilesystemAdapter::class))
            ->addMethodCall('setCallbackWrapper', [$dispatcher])
            ->addTag('container.reversible'));

        (new DecorateCachePoolsPass())->process($container);

        $decoratorId = DecorateCachePoolsPass::DECORATOR_PREFIX . 'app.files';
        self::assertEquals([['setCallbackWrapper', [$dispatcher]]], $container->getDefinition('app.files')->getMethodCalls());
        self::assertEquals(new Definition(CountingCallbackWrapper::class, [$previous, new Reference($decoratorId . '.computations')]), $dispatcher->getArgument(2));
        self::assertEquals([new Reference($decoratorId . '.inner'), 'setCallbackWrapper'], $previous->getFactory());
    }

    public function testAnOwnCallbackWrapperOfThePoolIsWrapped(): void
    {
        $container = $this->container(['app.files']);
        $partialDispatcher = new Definition(EarlyExpirationDispatcher::class, [new Reference('bus'), new Reference('reverse_container')]);
        $previous = new Definition('callable');
        $dispatcher = new Definition(EarlyExpirationDispatcher::class, [new Reference('bus'), new Reference('reverse_container'), $previous]);
        $container->setDefinition('app.files', (new Definition(FilesystemAdapter::class))
            ->addMethodCall('setLogger', [new Reference('logger')])
            ->addMethodCall('setCallbackWrapper', [null])
            ->addMethodCall('setCallbackWrapper', [$dispatcher])
            ->addMethodCall('setCallbackWrapper', [new Reference('app.wrapper')])
            ->addMethodCall('setCallbackWrapper', [$partialDispatcher]));

        (new DecorateCachePoolsPass())->process($container);

        $counter = new Reference(DecorateCachePoolsPass::DECORATOR_PREFIX . 'app.files.computations');
        self::assertEquals(
            [
                ['setLogger', [new Reference('logger')]],
                ['setCallbackWrapper', [null]],
                ['setCallbackWrapper', [$dispatcher]],
                ['setCallbackWrapper', [new Definition(CountingCallbackWrapper::class, [new Reference('app.wrapper'), $counter])]],
                ['setCallbackWrapper', [new Definition(CountingCallbackWrapper::class, [$partialDispatcher, $counter])]],
            ],
            $container->getDefinition('app.files')->getMethodCalls(),
        );
        self::assertEquals(new Definition(CountingCallbackWrapper::class, [$previous, $counter]), $dispatcher->getArgument(2));
    }

    public function testAReversiblePoolStaysReversibleUnderItsInnerId(): void
    {
        $container = $this->container(['app.files', 'app.users']);
        $container->setDefinition('app.files', (new Definition(FilesystemAdapter::class))->addTag('container.reversible'));
        $container->setDefinition('app.users', new Definition(ArrayAdapter::class));

        (new DecorateCachePoolsPass())->process($container);

        self::assertTrue($container->getDefinition('app.files')->hasTag('container.do_not_inline'), 'a behavior tag: it stays on the decorated pool');
        self::assertFalse($container->getDefinition('app.users')->hasTag('container.do_not_inline'));
        self::assertSame([DecorateCachePoolsPass::DECORATOR_PREFIX . 'app.files.inner'], $container->getParameter(DecorateCachePoolsPass::REVERSIBLE_POOLS_PARAMETER));
    }

    public function testWithoutListedPoolsTheCountingServicesAreNotRegistered(): void
    {
        $container = $this->container([]);

        (new DecorateCachePoolsPass())->process($container);

        self::assertFalse($container->hasDefinition(CacheCollector::class));
        self::assertFalse($container->hasDefinition(CacheMetricsFlushListener::class));
    }

    public function testListedPoolsRegisterTheCollectorAndItsFlushListener(): void
    {
        $container = $this->container(['app.users']);
        $container->setDefinition('app.users', new Definition(ArrayAdapter::class));

        (new DecorateCachePoolsPass())->process($container);

        self::assertTrue($container->getDefinition(CacheCollector::class)->isAutowired());
        $listener = $container->getDefinition(CacheMetricsFlushListener::class);
        self::assertTrue($listener->isAutowired());
        self::assertSame(
            [
                ['event' => 'kernel.terminate', 'method' => 'flush', 'priority' => -8192],
                ['event' => 'console.terminate', 'method' => 'flush', 'priority' => -8192],
                ['event' => WorkerMessageHandledEvent::class, 'method' => 'flush', 'priority' => -8192],
                ['event' => WorkerMessageFailedEvent::class, 'method' => 'flush', 'priority' => -8192],
            ],
            $listener->getTag('kernel.event_listener'),
        );
        self::assertSame([['method' => 'flush']], $listener->getTag('kernel.reset'));
    }

    public function testNonNamesInThePoolListAreSkipped(): void
    {
        $container = $this->container(['app.users']);
        $container->setParameter(DecorateCachePoolsPass::POOLS_PARAMETER, [42, 'app.users']);
        $container->setDefinition('app.users', new Definition(ArrayAdapter::class));

        (new DecorateCachePoolsPass())->process($container);

        self::assertTrue($container->hasDefinition(DecorateCachePoolsPass::DECORATOR_PREFIX . 'app.users'));
    }

    public function testWithoutListedPoolsNothingIsDecorated(): void
    {
        $container = $this->container([]);
        $container->setDefinition('app.users', new Definition(ArrayAdapter::class));

        $definitions = $container->getDefinitions();

        (new DecorateCachePoolsPass())->process($container);

        self::assertSame($definitions, $container->getDefinitions());
        self::assertFalse($container->hasParameter(DecorateCachePoolsPass::REVERSIBLE_POOLS_PARAMETER));
    }

    public function testFindsTheClassOfAPoolThroughItsParents(): void
    {
        $container = $this->container(['app.users']);
        $container->setParameter('app.adapter.class', ArrayAdapter::class);
        $container->setDefinition('cache.adapter.array', (new Definition('%app.adapter.class%'))->setAbstract(true));
        $container->setDefinition('app.parent', new ChildDefinition('cache.adapter.array'));
        $container->setDefinition('app.users', new ChildDefinition('app.parent'));

        (new DecorateCachePoolsPass())->process($container);

        self::assertSame(CountingCacheAdapter::class, $container->getDefinition(DecorateCachePoolsPass::DECORATOR_PREFIX . 'app.users')->getClass());
    }

    public function testAPoolBuiltByAFactoryIsTypedByTheAdapterInterface(): void
    {
        $container = $this->container(['cache.system']);
        $container->setDefinition('cache.system', (new Definition(AdapterInterface::class))->setFactory([AbstractAdapter::class, 'createSystemCache']));

        (new DecorateCachePoolsPass())->process($container);

        self::assertSame(CountingCacheAdapter::class, $container->getDefinition(DecorateCachePoolsPass::DECORATOR_PREFIX . 'cache.system')->getClass());
    }

    public function testAClassOfTheChildDefinitionWinsOverItsParent(): void
    {
        $container = $this->container(['app.tagged']);
        $container->setDefinition('cache.adapter.array', (new Definition(ArrayAdapter::class))->setAbstract(true));
        $container->setDefinition('app.tagged', (new ChildDefinition('cache.adapter.array'))->setClass(TagAwareAdapter::class));

        (new DecorateCachePoolsPass())->process($container);

        self::assertSame(CountingTagAwareCacheAdapter::class, $container->getDefinition(DecorateCachePoolsPass::DECORATOR_PREFIX . 'app.tagged')->getClass());
    }

    public function testAPoolOfAMissingClassIsAnError(): void
    {
        $container = $this->container(['app.users']);
        $container->setDefinition('app.users', new Definition('Not\\Installed\\Adapter'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Service "app.users" listed in msstc4symfony_metrics.cache.pools is not a Symfony cache pool');

        (new DecorateCachePoolsPass())->process($container);
    }

    public function testAPoolWhoseClassExtendsAMissingClassIsAnError(): void
    {
        // Generated at run time: a source file extending a missing class would fail static analysis.
        $class = 'Msstc4Symfony\\MetricsBundle\\Test\\Generated\\OrphanAdapter';
        $file = sys_get_temp_dir() . '/msstc4symfony-metrics-orphan-adapter-' . getmypid() . '.php';
        file_put_contents($file, "<?php\nnamespace Msstc4Symfony\\MetricsBundle\\Test\\Generated;\nfinal class OrphanAdapter extends \\Not\\Installed\\BaseAdapter {}\n");
        $autoload = static function (string $name) use ($class, $file): void {
            if ($name === $class) {
                require $file;
            }
        };
        spl_autoload_register($autoload);

        try {
            $container = $this->container(['app.users']);
            $container->setDefinition('app.users', new Definition($class));

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Service "app.users" listed in msstc4symfony_metrics.cache.pools is not a Symfony cache pool');

            (new DecorateCachePoolsPass())->process($container);
        } finally {
            spl_autoload_unregister($autoload);
            unlink($file);
        }
    }

    public function testATagAwarePoolGetsTheTagAwareDecorator(): void
    {
        $container = $this->container(['app.tagged']);
        $container->setDefinition('app.tagged', new Definition(TagAwareAdapter::class, [new Reference('.app.tagged.inner')]));

        (new DecorateCachePoolsPass())->process($container);

        self::assertSame(CountingTagAwareCacheAdapter::class, $container->getDefinition(DecorateCachePoolsPass::DECORATOR_PREFIX . 'app.tagged')->getClass());
    }

    public function testAnAliasIsDecoratedAtItsTargetUnderTheListedName(): void
    {
        $container = $this->container(['app.alias', 'app.users']);
        $container->setDefinition('app.users', new Definition(ArrayAdapter::class));
        $container->setAlias('app.alias', 'app.users');

        (new DecorateCachePoolsPass())->process($container);

        $decorator = $container->getDefinition(DecorateCachePoolsPass::DECORATOR_PREFIX . 'app.users');
        self::assertSame('app.alias', $decorator->getArgument(1));
        self::assertSame(['app.users', null, 0], $decorator->getDecoratedService());
    }

    public function testKeepsThePoolNameForTheClearerAndPrunerCommands(): void
    {
        $container = $this->container(['app.files', 'app.named']);
        $container->setDefinition('app.files', (new Definition(FilesystemAdapter::class))->addTag('cache.pool', ['pruneable' => true]));
        $container->setDefinition('app.named', (new Definition(ArrayAdapter::class))->addTag('cache.pool', ['name' => 'named']));

        (new DecorateCachePoolsPass())->process($container);

        self::assertSame([['pruneable' => true, 'name' => 'app.files']], $container->getDefinition('app.files')->getTag('cache.pool'));
        self::assertSame([['name' => 'named']], $container->getDefinition('app.named')->getTag('cache.pool'));
    }

    public function testTheEarlyExpirationCallbackWrapperStaysOnThePool(): void
    {
        $container = $this->container(['app.users']);
        $wrapper = (new Definition('callable'))->setFactory([new Reference('app.users'), 'setCallbackWrapper'])->addArgument(null);
        $other = (new Definition('callable'))->setFactory([new Reference('app.other'), 'setCallbackWrapper'])->addArgument(null);
        $otherMethod = (new Definition('callable'))->setFactory([new Reference('app.users'), 'getCallbackWrapper'])->addArgument(null);
        $otherCall = (new Definition('callable'))->setFactory([new Reference('app.users'), 'setCallbackWrapper'])->addArgument(null);
        $container->setDefinition('app.users', (new Definition(ArrayAdapter::class))
            ->addMethodCall('setLogger', [new Reference('logger')])
            ->addMethodCall('setCallbackWrapper', [new Reference('app.dispatcher')])
            ->addMethodCall('setCallbackWrapper', [new Definition(EarlyExpirationDispatcher::class, [new Reference('bus'), new Reference('reverse_container')])])
            ->addMethodCall('configure', [new Definition(EarlyExpirationDispatcher::class, [new Reference('bus'), new Reference('reverse_container'), $otherCall])])
            ->addMethodCall('setCallbackWrapper', [new Definition(EarlyExpirationDispatcher::class, [new Reference('bus'), new Reference('reverse_container'), $otherMethod])])
            ->addMethodCall('setCallbackWrapper', [new Definition(EarlyExpirationDispatcher::class, [new Reference('bus'), new Reference('reverse_container'), $other])])
            ->addMethodCall('setCallbackWrapper', [new Definition(EarlyExpirationDispatcher::class, [new Reference('bus'), new Reference('reverse_container'), $wrapper])]));

        (new DecorateCachePoolsPass())->process($container);

        self::assertEquals([new Reference(DecorateCachePoolsPass::DECORATOR_PREFIX . 'app.users.inner'), 'setCallbackWrapper'], $wrapper->getFactory());
        self::assertEquals([new Reference('app.other'), 'setCallbackWrapper'], $other->getFactory());
        self::assertEquals([new Reference('app.users'), 'getCallbackWrapper'], $otherMethod->getFactory());
        self::assertEquals([new Reference('app.users'), 'setCallbackWrapper'], $otherCall->getFactory());
    }

    public function testAnAliasOfAnUnmeasurablePoolIsAnError(): void
    {
        $container = $this->container(['app.throttle']);
        $container->setDefinition('metrics.cache', new Definition(ArrayAdapter::class));
        $container->setAlias('app.throttle', 'metrics.cache');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cache pool "app.throttle" listed in msstc4symfony_metrics.cache.pools is "metrics.cache", which cannot be measured.');

        (new DecorateCachePoolsPass())->process($container);
    }

    public function testThePoolBehindAnUnmeasurableAliasIsAnError(): void
    {
        $container = $this->container(['cache.app']);
        $container->setDefinition('cache.app', new Definition(ArrayAdapter::class));
        $container->setAlias('metrics.cache', 'cache.app');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cache pool "cache.app" listed in msstc4symfony_metrics.cache.pools is "metrics.cache", which cannot be measured.');

        (new DecorateCachePoolsPass())->process($container);
    }

    public function testAMissingPoolIsAnError(): void
    {
        $container = $this->container(['app.missing']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cache pool "app.missing" listed in msstc4symfony_metrics.cache.pools does not exist.');

        (new DecorateCachePoolsPass())->process($container);
    }

    public function testAServiceThatIsNoSymfonyCachePoolIsAnError(): void
    {
        $container = $this->container(['app.service']);
        $container->setDefinition('app.service', new Definition(stdClass::class));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Service "app.service" listed in msstc4symfony_metrics.cache.pools is not a Symfony cache pool: its class must implement Symfony\Component\Cache\Adapter\AdapterInterface.');

        (new DecorateCachePoolsPass())->process($container);
    }

    public function testAnAbstractDefinitionIsNoPool(): void
    {
        $container = $this->container(['cache.adapter.array']);
        $container->setDefinition('cache.adapter.array', (new Definition(ArrayAdapter::class))->setAbstract(true));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Service "cache.adapter.array" listed in msstc4symfony_metrics.cache.pools is not a Symfony cache pool');

        (new DecorateCachePoolsPass())->process($container);
    }

    /**
     * @param list<string> $pools
     */
    private function container(array $pools): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(DecorateCachePoolsPass::POOLS_PARAMETER, $pools);

        return $container;
    }
}
