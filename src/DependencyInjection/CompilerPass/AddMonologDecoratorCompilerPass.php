<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\DependencyInjection\CompilerPass;

use Msstc4Symfony\MetricsBundle\Application\LogChannel;
use Msstc4Symfony\MetricsBundle\DependencyInjection\DefinitionFilter;
use Msstc4Symfony\MetricsBundle\Framework\Monolog\HandlerDecorator;
use Override;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @internal
 */
final class AddMonologDecoratorCompilerPass implements CompilerPassInterface
{
    private const EXCLUDE_CHANNELS = [
        'profiling',
        'removal_request',
        'deprecation',
        LogChannel::NAME,
    ];

    #[Override]
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            if (
                !str_starts_with($id, 'monolog.logger')
                || in_array(substr($id, 15), self::EXCLUDE_CHANNELS, true)
                || $definition->getClass() === HandlerDecorator::class
                || !DefinitionFilter::isDecoratable($definition)
            ) {
                continue;
            }

            $container->register($id . '.decorator.metrics', HandlerDecorator::class)
                ->setDecoratedService($id)
                ->setAutowired(true)
                ->setPublic(true)
            ;
        }
    }
}
