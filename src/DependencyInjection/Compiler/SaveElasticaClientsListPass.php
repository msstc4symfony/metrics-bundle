<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\DependencyInjection\Compiler;

use Elastica\Client;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class SaveElasticaClientsListPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $ids = [];
        foreach ($container->getDefinitions() as $id => $definition) {
            if (
                $definition->getClass() === null
                || $definition->getClass() !== Client::class
                || $definition->isAbstract()
                || $definition->getDecoratedService() !== null
            ) {
                continue;
            }

            $ids[] = $id;

            $definition->setPublic(true);
        }

        $container->setParameter('metrics.elastica.clients', $ids);
    }
}
