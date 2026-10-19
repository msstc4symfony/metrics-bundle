<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\Definition;

/**
 * @internal
 */
final class DefinitionFilter
{
    public static function isDecoratable(Definition $definition): bool
    {
        return !$definition->isAbstract() && $definition->getDecoratedService() === null;
    }
}
