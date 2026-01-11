<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\DependencyInjection;

use Exception;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class MetricsExtension extends Extension
{
    /**
     * @param array<array-key, mixed> $configs
     *
     * @throws Exception
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));

        /** @psalm-suppress ReservedWord */
        $loader->load('services.yaml');
    }
}
