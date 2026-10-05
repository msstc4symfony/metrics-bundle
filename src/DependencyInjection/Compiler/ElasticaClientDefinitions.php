<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\DependencyInjection\Compiler;

use Elastica\Client;
use ReflectionClass;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

/**
 * @internal
 */
final class ElasticaClientDefinitions
{
    /**
     * @return array<string, Definition> service id => definition of Elastica\Client or a subclass
     */
    public static function find(ContainerBuilder $container): array
    {
        if (!class_exists(Client::class)) {
            return [];
        }

        $found = [];
        foreach ($container->getDefinitions() as $id => $definition) {
            if (!DefinitionFilter::isDecoratable($definition)) {
                continue;
            }

            $class = $container->getParameterBag()->resolveValue(self::className($container, $definition));
            $reflection = \is_string($class) ? $container->getReflectionClass($class, false) : null;
            if ($reflection instanceof ReflectionClass && ($reflection->getName() === Client::class || $reflection->isSubclassOf(Client::class))) {
                $found[$id] = $definition;
            }
        }

        return $found;
    }

    /**
     * Definitions from the nearest one up the parent chain (ChildDefinitions are not resolved yet when the
     * passes run, e.g. FOSElasticaBundle's clients take their class from the abstract "fos_elastica.client_prototype").
     *
     * @return iterable<int, Definition>
     */
    public static function lineage(ContainerBuilder $container, Definition $definition): iterable
    {
        $seen = [];
        while (true) {
            yield $definition;

            if (!$definition instanceof ChildDefinition) {
                return;
            }

            $parent = $definition->getParent();
            if (isset($seen[$parent]) || !$container->has($parent)) {
                return;
            }

            $seen[$parent] = true;
            try {
                $definition = $container->findDefinition($parent);
            } catch (ServiceNotFoundException) {
                return;
            }
        }
    }

    private static function className(ContainerBuilder $container, Definition $definition): ?string
    {
        foreach (self::lineage($container, $definition) as $ancestor) {
            $class = $ancestor->getClass();
            if ($class !== null) {
                return $class;
            }
        }

        return null;
    }
}
