<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\DependencyInjection\Compiler;

use Elastic\Transport\Transport;
use Elastica\Client;
use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\ElasticaCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Elastica\ConfiguredHttpClientFactory;
use Msstc4Symfony\MetricsBundle\Infrastructure\Elastica\TimingHttpClient;
use Override;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Elastica 8: puts TimingHttpClient in front of each client's PSR-18 client through its "transport_config".
 */
final class DecorateElasticaClientsPass implements CompilerPassInterface
{
    /**
     * Where the client's first argument may sit: ChildDefinition::replaceArgument(0) stores "index_0".
     */
    private const array CONFIG_KEYS = ['index_0', '$config', 0];

    /**
     * Applied by ConfiguredHttpClientFactory before wrapping; Elastica would reject them on the wrapper.
     */
    private const array CLIENT_OPTIONS = ['http_client_config', 'http_client_options'];

    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (!class_exists(Transport::class) || !class_exists(Client::class)) {
            return;
        }

        foreach (ElasticaClientDefinitions::find($container) as $id => $definition) {
            [$key, $config] = $this->config($container, $definition);
            if (!\is_array($config)) {
                $this->skip($container, $id, 'its configuration is not a literal array');

                continue;
            }

            $transportConfig = $config['transport_config'] ?? [];
            if (!\is_array($transportConfig)) {
                $this->skip($container, $id, 'its "transport_config" is not a literal array');

                continue;
            }

            $inner = new Definition(ClientInterface::class)
                ->setFactory([ConfiguredHttpClientFactory::class, 'create'])
                ->setArguments([
                    $transportConfig['http_client'] ?? null,
                    $transportConfig[self::CLIENT_OPTIONS[0]] ?? null,
                    $transportConfig[self::CLIENT_OPTIONS[1]] ?? null,
                ])
            ;
            foreach (self::CLIENT_OPTIONS as $option) {
                unset($transportConfig[$option]);
            }

            $transportConfig['http_client'] = new Definition(TimingHttpClient::class, [$inner, new Reference(ElasticaCollector::class)]);
            $config['transport_config'] = $transportConfig;

            if ($definition instanceof ChildDefinition) {
                // Replaced, never appended: ResolveChildDefinitionsPass merges "index_0" / "$config" over the parent's argument.
                $definition->replaceArgument($key === 'index_0' ? 0 : $key, $config);
            } else {
                $definition->setArgument($key === 'index_0' ? 0 : $key, $config);
            }
        }
    }

    /**
     * @return array{int|string, mixed} the argument key as stored and its value, looked up the parent chain
     */
    private function config(ContainerBuilder $container, Definition $definition): array
    {
        foreach (ElasticaClientDefinitions::lineage($container, $definition) as $ancestor) {
            $arguments = $ancestor->getArguments();
            foreach (self::CONFIG_KEYS as $key) {
                if (\array_key_exists($key, $arguments)) {
                    return [$key, $arguments[$key]];
                }
            }
        }

        return [0, []];
    }

    private function skip(ContainerBuilder $container, string $id, string $reason): void
    {
        $container->log($this, \sprintf('Elastica client "%s" is not measured: %s.', $id, $reason));
    }
}
