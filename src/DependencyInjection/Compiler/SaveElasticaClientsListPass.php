<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\DependencyInjection\Compiler;

use Elastica\Client;
use Override;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class SaveElasticaClientsListPass implements CompilerPassInterface
{
    public const string PARAMETER = 'msstc4symfony_metrics.elastica.clients';

    #[Override]
    public function process(ContainerBuilder $container): void
    {
        $ids = [];
        foreach ($container->getDefinitions() as $id => $definition) {
            if (
                $definition->getClass() !== Client::class
                || !DefinitionFilter::isDecoratable($definition)
            ) {
                continue;
            }

            $ids[] = $id;

            $definition->setPublic(true);
        }

        $container->setParameter(self::PARAMETER, $ids);
    }
}
