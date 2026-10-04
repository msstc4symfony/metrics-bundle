<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Kernel;

use Msstc4Symfony\MetricsBundle\MetricsBundle;
use Override;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * FrameworkBundle, MonologBundle and MetricsBundle only (no Messenger, Doctrine), configured
 * with the given "msstc4symfony_metrics" tree; null means the application has no config file.
 *
 * Bundle configuration as an application writes it; values stay loose so tests can pass invalid ones.
 *
 * @phpstan-type MetricsConfigShape array{
 *     storage?: array{dsn?: string, reconnect_backoff_seconds?: float|int|string},
 *     application_name?: string,
 *     component_name?: string,
 *     errors?: array{short_exception_class_name?: bool},
 *     http_client?: array{sanitize_path?: bool},
 *     metric_enums?: list<string>,
 * }
 */
final class BundleConfigKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param MetricsConfigShape|null $metricsConfig
     */
    public function __construct(
        private readonly ?array $metricsConfig,
    ) {
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
        return TestKernel::cacheRoot() . '/bundle-config/' . md5(serialize($this->metricsConfig));
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
            'messenger' => false,
        ]);

        $container->extension('monolog', ['handlers' => ['main' => ['type' => 'null']]]);

        if ($this->metricsConfig !== null) {
            $container->extension('msstc4symfony_metrics', $this->metricsConfig);
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('routing.controllers');
    }
}
