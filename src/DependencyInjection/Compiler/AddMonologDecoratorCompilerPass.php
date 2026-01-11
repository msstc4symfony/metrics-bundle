<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\DependencyInjection\Compiler;

use MaxShamaev\MetricsBundle\Infrastructure\Monolog\Handler\HandlerDecorator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class AddMonologDecoratorCompilerPass implements CompilerPassInterface
{
    /**
     * @var string[]
     */
    private array $excludeChannels = [
        'profiling',
        'removal_request',
        'deprecation',
    ];

    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            if (
                !str_starts_with($id, 'monolog.logger')
                || in_array(substr($id, 15), $this->excludeChannels, true)
                || $definition->getClass() === HandlerDecorator::class
                || $definition->isAbstract()
                || $definition->getDecoratedService() !== null
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
