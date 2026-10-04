<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Definition;

final class DefinitionFilter
{
    public static function isDecoratable(Definition $definition): bool
    {
        return !$definition->isAbstract() && $definition->getDecoratedService() === null;
    }
}
