<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Kernel;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
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

    public const string HTTP_CLIENT_ALIAS = 'test.http_client';

    public const string SCOPED_CLIENT = 'github.client';

    public const string SCOPED_CLIENT_ALIAS = 'test.github.client';

    // Compiler passes of equal priority run in bundle registration order; this env flips it.
    public const string ENV_METRICS_BUNDLE_FIRST = 'metrics_first';

    // The test connection is sqlite in memory.
    public static function hasDoctrine(): bool
    {
        return class_exists(DoctrineBundle::class) && extension_loaded('pdo_sqlite');
    }

    // Per process: infection runs PHPUnit in parallel and setUp() wipes this directory.
    public static function cacheRoot(): string
    {
        return sys_get_temp_dir() . '/msstc4symfony-metrics-bundle-test-' . getmypid();
    }

    #[Override]
    public function registerBundles(): iterable
    {
        $bundles = [new FrameworkBundle(), new MonologBundle()];
        $doctrine = self::hasDoctrine() ? [new DoctrineBundle()] : [];

        return $this->environment === self::ENV_METRICS_BUNDLE_FIRST
            ? [...$bundles, new MetricsBundle(), ...$doctrine]
            : [...$bundles, ...$doctrine, new MetricsBundle()];
    }

    #[Override]
    public function getCacheDir(): string
    {
        return self::cacheRoot() . '/cache/' . $this->environment;
    }

    #[Override]
    public function getLogDir(): string
    {
        return self::cacheRoot() . '/log';
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
            $framework['http_client'] = [
                'mock_response_factory' => 'test.mock_response',
                'scoped_clients' => [self::SCOPED_CLIENT => ['base_uri' => 'https://api.github.com']],
            ];
        }

        $container->extension('framework', $framework);

        if (class_exists(HttpClient::class)) {
            $services = $container->services();
            $services->set('test.mock_response', MockResponseFactory::class);
            // Unused services are removed on compile; the test needs to fetch these.
            $services->alias(self::HTTP_CLIENT_ALIAS, 'http_client')->public();
            $services->alias(self::SCOPED_CLIENT_ALIAS, self::SCOPED_CLIENT)->public();
        }
        if (self::hasDoctrine()) {
            $container->extension('doctrine', [
                'dbal' => ['driver' => 'pdo_sqlite', 'memory' => true],
            ]);
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
