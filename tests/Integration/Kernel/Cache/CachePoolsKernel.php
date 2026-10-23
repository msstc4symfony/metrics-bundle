<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\Cache;

use Msstc4Symfony\MetricsBundle\MetricsBundle;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\TestKernel;
use Override;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Framework cache pools of each kind, three of them listed in msstc4symfony_metrics.cache.pools.
 */
final class CachePoolsKernel extends Kernel
{
    use MicroKernelTrait;

    public const PLAIN_POOL = 'app.plain';

    public const TAGGED_POOL = 'app.tagged';

    public const FILES_POOL = 'app.files';

    public const UNLISTED_POOL = 'app.unlisted';

    public const CHAIN_POOL = 'app.chain';

    public function __construct(
        private readonly bool $profiler = false,
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
        return TestKernel::cacheRoot() . '/cache-pools' . ($this->profiler ? '-profiler' : '');
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
            'profiler' => ['enabled' => $this->profiler, 'collect' => false],
            'cache' => [
                'pools' => [
                    self::PLAIN_POOL => ['adapter' => 'cache.adapter.array'],
                    self::TAGGED_POOL => ['adapter' => 'cache.adapter.array', 'tags' => true],
                    self::FILES_POOL => ['adapter' => 'cache.adapter.filesystem'],
                    self::UNLISTED_POOL => ['adapter' => 'cache.adapter.array'],
                    self::CHAIN_POOL => ['adapters' => ['cache.adapter.array', 'cache.adapter.filesystem']],
                ],
            ],
        ]);
        $container->extension('monolog', ['handlers' => ['main' => ['type' => 'null']]]);
        $container->extension('msstc4symfony_metrics', [
            'storage' => ['dsn' => 'inmemory://'],
            'cache' => ['pools' => [self::PLAIN_POOL, self::TAGGED_POOL, self::FILES_POOL, self::CHAIN_POOL]],
        ]);
        $container->services()->set(CachePoolsConsumer::class)->autowire()->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('routing.controllers');
    }
}
