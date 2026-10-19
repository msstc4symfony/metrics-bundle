<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\DependencyInjection\CompilerPass;

use Elastica\Transport\AbstractTransport;
use Msstc4Symfony\MetricsBundle\DependencyInjection\ElasticaClientDefinitions;
use Override;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @internal
 */
final class SaveElasticaClientsListPass implements CompilerPassInterface
{
    public const PARAMETER = 'msstc4symfony_metrics.elastica.clients';

    #[Override]
    public function process(ContainerBuilder $container): void
    {
        // The Elastica 7 transport API this list feeds is gone in Elastica 8+ (DecorateElasticaClientsPass covers 8 and 9).
        if (!class_exists(AbstractTransport::class)) {
            $container->setParameter(self::PARAMETER, []);

            return;
        }

        $ids = [];
        foreach (ElasticaClientDefinitions::find($container) as $id => $definition) {
            $ids[] = $id;

            $definition->setPublic(true);
        }

        $container->setParameter(self::PARAMETER, $ids);
    }
}
