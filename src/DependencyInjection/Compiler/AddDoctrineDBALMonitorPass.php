<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\DependencyInjection\Compiler;

use Doctrine\DBAL\Connection;
use MaxShamaev\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics\Middleware;
use Override;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class AddDoctrineDBALMonitorPass implements CompilerPassInterface
{
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            if (
                $definition->getClass() === null
                || preg_match('/^doctrine.dbal.[\w_]+_connection$/', $id) !== 1
                || !is_a($definition->getClass(), Connection::class, true)
                || !DefinitionFilter::isDecoratable($definition)
            ) {
                continue;
            }

            $definition = new Definition(Middleware::class)
                ->setAutowired(true)
            ;
            $container->setDefinition(Middleware::class, $definition);
            break;
        }
    }
}
