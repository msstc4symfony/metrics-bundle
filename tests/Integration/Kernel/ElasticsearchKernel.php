<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Kernel;

use Elastic\Transport\Transport;
use LogicException;
use Msstc4Symfony\MetricsBundle\MetricsBundle;
use Msstc4Symfony\MetricsBundle\Test\Support\SubclassedElasticaClient;
use Override;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * An application with one Elastica client (a subclass, as FOSElasticaBundle registers) pointed at ELASTICSEARCH_URL.
 */
final class ElasticsearchKernel extends Kernel
{
    use MicroKernelTrait;

    public const string URL_ENV = 'ELASTICSEARCH_URL';

    public const string CLIENT = 'app.elastica';

    public static function url(): ?string
    {
        $url = getenv(self::URL_ENV);

        return \is_string($url) && $url !== '' ? $url : null;
    }

    #[Override]
    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new MonologBundle(), new MetricsBundle()];
    }

    #[Override]
    public function getCacheDir(): string
    {
        return TestKernel::cacheRoot() . '/elasticsearch/cache';
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

        $container->extension('msstc4symfony_metrics', [
            'storage' => ['dsn' => 'inmemory://'],
            'application_name' => 'app',
            'component_name' => 'cmp',
        ]);

        $container->services()->set(self::CLIENT, SubclassedElasticaClient::class)->args([$this->clientConfig()])->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
    }

    /**
     * @return array{hosts: list<string>, transport_config: array{http_client_options: array{timeout: int}}}|array{host: string, port: int}
     */
    private function clientConfig(): array
    {
        $url = self::url() ?? throw new LogicException(self::URL_ENV . ' is not set.');
        if (class_exists(Transport::class)) {
            // Options FOSElasticaBundle 7 always sets; Elastica applies them only to the unwrapped client.
            return ['hosts' => [$url], 'transport_config' => ['http_client_options' => ['timeout' => 5]]];
        }

        // Elastica 7 rejects a "url" without a path as malformed.
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        if (!\is_string($host) || !\is_int($port)) {
            throw new LogicException(\sprintf('%s must be scheme://host:port, got "%s".', self::URL_ENV, $url));
        }

        return ['host' => $host, 'port' => $port];
    }
}
