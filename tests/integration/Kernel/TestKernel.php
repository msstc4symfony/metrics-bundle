<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Kernel;

use Msstc4Symfony\MetricsBundle\MetricsBundle;
use Override;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public const string CACHE_ROOT = '/msstc4symfony-metrics-bundle-test';

    public const string HTTP_CLIENT_ALIAS = 'test.http_client';

    #[Override]
    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new MonologBundle(),
            new MetricsBundle(),
        ];
    }

    #[Override]
    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . self::CACHE_ROOT . '/cache/' . $this->environment;
    }

    #[Override]
    public function getLogDir(): string
    {
        return sys_get_temp_dir() . self::CACHE_ROOT . '/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $framework = [
            'secret' => 'test',
            'http_method_override' => false,
            // Symfony 6.4 deprecates leaving it unset.
            'handle_all_throwables' => true,
            'test' => true,
            'router' => ['utf8' => true],
            // The php_errors logger installs a global handler that outlives the kernel and trips failOnRisky.
            'php_errors' => ['log' => false],
        ];

        if (class_exists(HttpClient::class)) {
            $framework['http_client'] = [];
        }

        $container->extension('framework', $framework);

        if (class_exists(HttpClient::class)) {
            // Unused services are removed on compile; the test needs to fetch this one.
            $container->services()->alias(self::HTTP_CLIENT_ALIAS, 'http_client')->public();
        }
        $container->extension('monolog', [
            'handlers' => ['main' => ['type' => 'null']],
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        // The import README tells applications to add.
        $routes->import('@MetricsBundle/Presentation/Controller/', 'attribute');
    }
}
