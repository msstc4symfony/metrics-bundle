<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\Messenger;

use Msstc4Symfony\MetricsBundle\MetricsBundle;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\TestKernel;
use Msstc4Symfony\MetricsBundle\Test\Support\Messenger\CountableReceiver;
use Override;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Messenger with an in-memory transport (cannot count its messages) and a countable receiver, with
 * msstc4symfony_metrics.messenger.queue_depth configured as given.
 *
 * @phpstan-type QueueDepthConfigShape array{enabled?: bool, transports?: list<string>, period?: string}
 */
final class QueueDepthKernel extends Kernel
{
    use MicroKernelTrait;

    public const IN_MEMORY_TRANSPORT = 'async';

    public const COUNTABLE_TRANSPORT = 'countable';

    public const COUNTABLE_RECEIVER = 'test.countable_receiver';

    /**
     * @param QueueDepthConfigShape|null $queueDepth null leaves the option out
     */
    public function __construct(
        private readonly ?array $queueDepth,
        private readonly bool $metricsPool = true,
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
        return TestKernel::cacheRoot() . '/queue-depth/' . md5(serialize([$this->queueDepth, $this->metricsPool]));
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
            'messenger' => ['transports' => [self::IN_MEMORY_TRANSPORT => 'in-memory://']],
            // As doc/cache.yaml advises: the pool also gets the named autowiring alias CacheInterface $metricsCache.
            'cache' => ['pools' => $this->metricsPool ? ['metrics.cache' => ['adapter' => 'cache.adapter.array']] : []],
        ]);
        $container->extension('monolog', ['handlers' => ['main' => ['type' => 'null']]]);
        $container->extension('msstc4symfony_metrics', ['storage' => ['dsn' => 'inmemory://']] + ($this->queueDepth === null ? [] : ['messenger' => ['queue_depth' => $this->queueDepth]]));
        // Tagged through _instanceof: the tag arrives with the autoconfiguration passes.
        $services = $container->services();
        $services->instanceof(CountableReceiver::class)->tag('messenger.receiver', ['alias' => self::COUNTABLE_TRANSPORT]);
        $services->set(self::COUNTABLE_RECEIVER, CountableReceiver::class)->args([5])->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('routing.controllers');
    }
}
