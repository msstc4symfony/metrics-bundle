<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\DependencyInjection\Compiler;

use Msstc4Symfony\MetricsBundle\Infrastructure\Monolog\Handler\HandlerDecorator;
use Override;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class AddMonologDecoratorCompilerPass implements CompilerPassInterface
{
    private const array EXCLUDE_CHANNELS = [
        'profiling',
        'removal_request',
        'deprecation',
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
