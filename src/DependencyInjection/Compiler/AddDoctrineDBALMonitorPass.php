<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\DependencyInjection\Compiler;

use Doctrine\DBAL\Connection;
use MaxShamaev\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics\Middleware;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

class AddDoctrineDBALMonitorPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            if (
                $definition->getClass() === null
                || preg_match('/^doctrine.dbal.[\w_]+_connection$/', $id) !== 1
                || !is_a($definition->getClass(), Connection::class, true)
                || $definition->isAbstract()
                || $definition->getDecoratedService() !== null
            ) {
                continue;
            }

            $definition = (new Definition(Middleware::class))
                ->setAutowired(true)
            ;
            $container->setDefinition(Middleware::class, $definition);
            break;
        }
    }
}
