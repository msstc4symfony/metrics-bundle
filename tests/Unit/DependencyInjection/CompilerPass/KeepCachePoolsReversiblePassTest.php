<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\DependencyInjection\CompilerPass;

use Msstc4Symfony\MetricsBundle\DependencyInjection\CompilerPass\DecorateCachePoolsPass;
use Msstc4Symfony\MetricsBundle\DependencyInjection\CompilerPass\KeepCachePoolsReversiblePass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class KeepCachePoolsReversiblePassTest extends TestCase
{
    public function testTheDecoratedPoolsGetTheReversibleTagBack(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(DecorateCachePoolsPass::REVERSIBLE_POOLS_PARAMETER, [42, 'pool.inner', 'tagged.inner', 'gone.inner']);
        $container->register('pool.inner');
        $container->register('tagged.inner')->addTag('container.reversible');

        (new KeepCachePoolsReversiblePass())->process($container);

        self::assertSame([[]], $container->getDefinition('pool.inner')->getTag('container.reversible'));
        self::assertSame([[]], $container->getDefinition('tagged.inner')->getTag('container.reversible'));
        self::assertFalse($container->hasParameter(DecorateCachePoolsPass::REVERSIBLE_POOLS_PARAMETER));
    }

    public function testWithoutTheParameterNothingHappens(): void
    {
        $container = new ContainerBuilder();
        $container->register('pool.inner');

        (new KeepCachePoolsReversiblePass())->process($container);

        self::assertFalse($container->getDefinition('pool.inner')->hasTag('container.reversible'));
    }
}
