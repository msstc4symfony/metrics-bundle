<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\DependencyInjection\Compiler;

use MaxShamaev\MetricsBundle\Infrastructure\HttpClient\HttpClientDecorator;
use Override;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\ScopingHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** @phpstan-ignore function.notFound */
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

final class AddHttpClientMonitorPass implements CompilerPassInterface
{
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (!class_exists(HttpClientInterface::class)) {
            return;
        }

        foreach ($container->getDefinitions() as $id => $definition) {
            if (
                $definition->getClass() === null
                || !DefinitionFilter::isDecoratable($definition)
                || !in_array($definition->getClass(), [ScopingHttpClient::class, HttpClientInterface::class], true)
            ) {
                continue;
            }

            $decoratorId = $id . '.decorator.monitor';
            $decoratorDefinition = new Definition(HttpClientDecorator::class)
                ->setAutowired(true)
                ->setArgument('$inner', new Reference($decoratorId . '.inner'))
                /** @phpstan-ignore function.notFound */
                ->setArgument('$urlAssemblers', tagged_iterator('metrics.htp_client.url_assembler'))
                ->setDecoratedService($id)
            ;

            switch ($definition->getClass()) {
                case HttpClientInterface::class:
                    $arguments = $definition->getArguments();
                    if (
                        isset($arguments[0]['base_uri'])
                        && is_string($arguments[0]['base_uri'])
                    ) {
                        $decoratorDefinition->setArgument('$baseUri', $arguments[0]['base_uri']);
                    }
                    break;

                case ScopingHttpClient::class:
                    $arguments = $definition->getArguments();
                    if (isset($arguments[1]) && is_string($arguments[1])) {
                        $decoratorDefinition->setArgument('$baseUri', $arguments[1]);
                    }
                    break;
            }

            $container->setDefinition($decoratorId, $decoratorDefinition);
        }
    }
}
