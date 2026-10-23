<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\Cache;

use Msstc4Symfony\MetricsBundle\MetricsBundle;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\TestKernel;
use Override;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\Cache\Messenger\EarlyExpirationMessage;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * A listed filesystem pool recomputed early through Messenger, the messages kept in an in-memory transport.
 */
final class EarlyExpirationKernel extends Kernel
{
    use MicroKernelTrait;

    public const POOL = 'app.early';

    public const TRANSPORT = 'messenger.transport.early';

    public function __construct()
    {
        parent::__construct('test', true);
    }

    #[Override]
    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new MonologBundle(), new MetricsBundle()];
    }

    #[Override]
    public function getCacheDir(): string
    {
        return TestKernel::cacheRoot() . '/early-expiration';
    }

    #[Override]
    public function getLogDir(): string
    {
        return TestKernel::cacheRoot() . '/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'http_method_override' => false,
            'test' => true,
            'router' => ['utf8' => true],
            'php_errors' => ['log' => false],
            'messenger' => [
                'transports' => ['early' => 'in-memory://'],
                'routing' => [EarlyExpirationMessage::class => 'early'],
            ],
            'cache' => [
                'pools' => [
                    self::POOL => ['adapter' => 'cache.adapter.filesystem', 'early_expiration_message_bus' => 'messenger.default_bus'],
                ],
            ],
        ]);
        $container->extension('monolog', ['handlers' => ['main' => ['type' => 'null']]]);
        $container->extension('msstc4symfony_metrics', [
            'storage' => ['dsn' => 'inmemory://'],
            'cache' => ['pools' => [self::POOL]],
        ]);
        $container->services()->set(EarlyComputer::class)->public()->tag('container.reversible');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('routing.controllers');
    }
}
