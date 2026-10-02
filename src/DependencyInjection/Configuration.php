<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\DependencyInjection;

use Override;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public const string CONNECTION_LABEL_HOST_DBNAME = 'host_dbname';

    public const string CONNECTION_LABEL_NAME = 'name';

    #[Override]
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('metrics');

        $treeBuilder->getRootNode()
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('doctrine')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('connection_label')
                            ->info('Value of the "connection" label of DBAL metrics: "host_dbname" (host:dbname) or "name" (DoctrineBundle connection name).')
                            ->values([self::CONNECTION_LABEL_HOST_DBNAME, self::CONNECTION_LABEL_NAME])
                            ->defaultValue(self::CONNECTION_LABEL_HOST_DBNAME)
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
