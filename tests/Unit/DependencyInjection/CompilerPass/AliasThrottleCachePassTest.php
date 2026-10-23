<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\DependencyInjection\CompilerPass;

use Msstc4Symfony\MetricsBundle\DependencyInjection\CompilerPass\AliasThrottleCachePass;
use Msstc4Symfony\MetricsBundle\Presentation\EventListener\InfoEventListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Contracts\Cache\CacheInterface;

final class AliasThrottleCachePassTest extends TestCase
{
    public function testTheThrottleCacheIsTheMetricsCachePool(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('metrics.cache', new Definition(ArrayAdapter::class));
        $container->setDefinition('cache.app', new Definition(ArrayAdapter::class));

        (new AliasThrottleCachePass())->process($container);

        self::assertSame('metrics.cache', (string) $container->getAlias(AliasThrottleCachePass::THROTTLE_CACHE));
    }

    public function testTheInfoListenerReadsTheThrottleCache(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(InfoEventListener::class, new Definition(InfoEventListener::class));

        (new AliasThrottleCachePass())->process($container);

        self::assertEquals(new Reference(AliasThrottleCachePass::THROTTLE_CACHE), $container->getDefinition(InfoEventListener::class)->getArgument('$metricsCache'));
    }

    public function testTheThrottleCacheFallsBackToTheCacheInterfaceAlias(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('cache.app', new Definition(ArrayAdapter::class));
        $container->setAlias(CacheInterface::class, 'cache.app');

        (new AliasThrottleCachePass())->process($container);

        self::assertSame(CacheInterface::class, (string) $container->getAlias(AliasThrottleCachePass::THROTTLE_CACHE));
    }

    public function testAThrottleCacheTheApplicationGaveTheInfoListenerIsKept(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(InfoEventListener::class, (new Definition(InfoEventListener::class))->setArgument('$metricsCache', new Reference('app.pool')));

        (new AliasThrottleCachePass())->process($container);

        self::assertEquals(new Reference('app.pool'), $container->getDefinition(InfoEventListener::class)->getArgument('$metricsCache'));
    }
}
