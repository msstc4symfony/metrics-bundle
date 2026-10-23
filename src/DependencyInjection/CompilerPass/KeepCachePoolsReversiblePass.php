<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\DependencyInjection\CompilerPass;

use Override;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Tags the inner pools of reversible decorated pools as reversible again (DecoratorServicePass moved the tag to the
 * decorator), before FrameworkBundle collects reversible services after removing. TYPE_BEFORE_REMOVING.
 *
 * @internal
 */
final class KeepCachePoolsReversiblePass implements CompilerPassInterface
{
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(DecorateCachePoolsPass::REVERSIBLE_POOLS_PARAMETER)) {
            return;
        }

        $ids = $container->getParameter(DecorateCachePoolsPass::REVERSIBLE_POOLS_PARAMETER);
        $container->getParameterBag()->remove(DecorateCachePoolsPass::REVERSIBLE_POOLS_PARAMETER);

        foreach (\is_array($ids) ? array_filter($ids, \is_string(...)) : [] as $id) {
            $definition = $container->hasDefinition($id) ? $container->getDefinition($id) : null;
            if ($definition instanceof Definition && !$definition->hasTag('container.reversible')) {
                $definition->addTag('container.reversible');
            }
        }
    }
}
