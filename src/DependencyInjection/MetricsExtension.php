<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\DependencyInjection;

use Exception;
use Override;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

final class MetricsExtension extends Extension
{
    public const string DOCTRINE_CONNECTION_LABEL_PARAMETER = 'metrics_bundle.doctrine.connection_label';

    /**
     * @param array<array-key, mixed> $configs
     *
     * @throws Exception
     */
    #[Override]
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));

        /** @psalm-suppress ReservedWord */
        $loader->load('services.yaml');

        if (class_exists(WorkerMessageReceivedEvent::class)) {
            $loader->load('messenger.yaml');
        }

        $config = $this->processConfiguration(new Configuration(), $configs);
        $container->setParameter(self::DOCTRINE_CONNECTION_LABEL_PARAMETER, $this->doctrineConnectionLabel($config));
    }

    /**
     * @param array<array-key, mixed> $config
     */
    private function doctrineConnectionLabel(array $config): string
    {
        $doctrine = $config['doctrine'] ?? null;
        $connectionLabel = is_array($doctrine) ? $doctrine['connection_label'] ?? null : null;
        if (!is_string($connectionLabel)) {
            throw new LogicException('The processed "metrics.doctrine.connection_label" must be a string.');
        }

        return $connectionLabel;
    }
}
