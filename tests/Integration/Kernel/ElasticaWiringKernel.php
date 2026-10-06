<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Kernel;

use Elastic\Transport\Transport;
use Elastica\Response;
use Elastica\Transport\NullTransport;
use Msstc4Symfony\MetricsBundle\MetricsBundle;
use Msstc4Symfony\MetricsBundle\Test\Support\StaticJsonPsr18Client;
use Msstc4Symfony\MetricsBundle\Test\Support\SubclassedElasticaClient;
use Override;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * One Elastica client that never reaches the network: NullTransport on Elastica 7, a static PSR-18 client on 8.
 */
final class ElasticaWiringKernel extends Kernel
{
    use MicroKernelTrait;

    public const string CLIENT = 'app.elastica';

    public function __construct(
        private readonly ?bool $sanitizePath,
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
        return TestKernel::cacheRoot() . '/elastica-wiring/' . var_export($this->sanitizePath, true);
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

        $metrics = ['storage' => ['dsn' => 'inmemory://'], 'application_name' => 'app', 'component_name' => 'cmp'];
        if ($this->sanitizePath !== null) {
            $metrics['elastica'] = ['sanitize_path' => $this->sanitizePath];
        }

        $container->extension('msstc4symfony_metrics', $metrics);

        $services = $container->services();
        if (class_exists(Transport::class)) {
            $services->set('app.psr18', StaticJsonPsr18Client::class);
            $config = ['hosts' => ['http://es:9200'], 'transport_config' => ['http_client' => service('app.psr18')]];
        } else {
            // NullTransport's default response has no query time, which TimingTransport cannot record.
            $config = ['transport' => inline_service(NullTransport::class)->call('setResponse', [
                inline_service(Response::class)->args([StaticJsonPsr18Client::DOCUMENT])->call('setQueryTime', [0.01]),
            ])];
        }

        $services->set(self::CLIENT, SubclassedElasticaClient::class)->args([$config])->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
    }
}
