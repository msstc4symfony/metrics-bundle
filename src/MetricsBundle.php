<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle;

use Elastica\Client as ElasticaClient;
use Elastica\Transport\AbstractTransport;
use LogicException;
use MongoDB\Client;
use Msstc4Symfony\MetricsBundle\DependencyInjection\Compiler\AddDoctrineDBALMonitorPass;
use Msstc4Symfony\MetricsBundle\DependencyInjection\Compiler\AddHttpClientMonitorPass;
use Msstc4Symfony\MetricsBundle\DependencyInjection\Compiler\AddMonologDecoratorCompilerPass;
use Msstc4Symfony\MetricsBundle\DependencyInjection\Compiler\SaveElasticaClientsListPass;
use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\ElasticaCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\ODM\Metrics\TimingSubscriber;
use Msstc4Symfony\MetricsBundle\Infrastructure\Elastica\TimingTransport;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnumInterface;
use Override;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Throwable;

use function MongoDB\Driver\Monitoring\addSubscriber;

final class MetricsBundle extends AbstractBundle
{
    private const string PARAMETER_PREFIX = 'msstc4symfony_metrics.';

    private const string DEFAULT_DSN = 'redis://127.0.0.1:6379';

    private const string UNKNOWN = 'unknown';

    protected string $extensionAlias = 'msstc4symfony_metrics';

    /**
     * src/, not the package root: applications import "@MetricsBundle/Presentation/Controller/".
     */
    #[Override]
    public function getPath(): string
    {
        return __DIR__;
    }

    #[Override]
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('storage')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->stringNode('dsn')
                            ->info('Prometheus storage: redis://, redisng://, apc://, apcng:// or inmemory://.')
                            ->cannotBeEmpty()
                            ->defaultValue('%env(default:msstc4symfony_metrics.default_dsn:METRICS_STORAGE_DSN)%')
                        ->end()
                        ->floatNode('reconnect_backoff_seconds')
                            ->info('After a Redis connection failure, metric writes are dropped and reads fail without reconnecting for this long. 0 reconnects on every operation.')
                            ->min(0)
                            ->defaultValue(5.0)
                        ->end()
                    ->end()
                ->end()
                ->stringNode('application_name')
                    ->info('Value of the "application" label of every metric.')
                    ->cannotBeEmpty()
                    ->defaultValue('%env(default:msstc4symfony_metrics.unknown:APPLICATION_NAME)%')
                ->end()
                ->stringNode('component_name')
                    ->info('Value of the "component" label of every metric.')
                    ->cannotBeEmpty()
                    ->defaultValue('%env(default:msstc4symfony_metrics.unknown:COMPONENT_NAME)%')
                ->end()
                ->arrayNode('errors')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('short_exception_class_name')
                            ->info('Label exceptions with the short class name instead of the FQCN.')
                            ->defaultFalse()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('http_client')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('sanitize_path')
                            ->info('Replace identifiers in outgoing request paths with placeholders to bound the "path" label.')
                            ->defaultTrue()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('metric_enums')
                    ->info("Extra metric catalogs, appended after the bundle's own.")
                    ->scalarPrototype()
                        ->validate()
                            ->ifTrue(static fn (mixed $class): bool => !is_string($class) || !is_a($class, MetricLabelEnumInterface::class, true))
                            ->thenInvalid('%s is not an enum implementing ' . MetricLabelEnumInterface::class . '.')
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }

    /**
     * @param array<array-key, mixed> $config processed by configure()
     */
    #[Override]
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $storage = $this->section($config, 'storage');
        $backoff = $storage['reconnect_backoff_seconds'] ?? null;

        $container->parameters()
            ->set(self::PARAMETER_PREFIX . 'default_dsn', self::DEFAULT_DSN)
            ->set(self::PARAMETER_PREFIX . 'unknown', self::UNKNOWN)
            ->set(self::PARAMETER_PREFIX . 'storage.dsn', $storage['dsn'] ?? null)
            // floatNode keeps an integer as given; an env placeholder stays a string until runtime.
            ->set(self::PARAMETER_PREFIX . 'storage.reconnect_backoff_seconds', \is_int($backoff) ? (float) $backoff : $backoff)
            ->set(self::PARAMETER_PREFIX . 'application_name', $config['application_name'] ?? null)
            ->set(self::PARAMETER_PREFIX . 'component_name', $config['component_name'] ?? null)
            ->set(self::PARAMETER_PREFIX . 'errors.short_exception_class_name', $this->section($config, 'errors')['short_exception_class_name'] ?? null)
            ->set(self::PARAMETER_PREFIX . 'http_client.sanitize_path', $this->section($config, 'http_client')['sanitize_path'] ?? null)
            ->set(self::PARAMETER_PREFIX . 'metric_enums', [MetricLabelEnum::class, ...array_values($this->section($config, 'metric_enums'))])
        ;

        $container->import(__DIR__ . '/Resources/config/services.php');
    }

    #[Override]
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new AddMonologDecoratorCompilerPass());
        $container->addCompilerPass(new AddHttpClientMonitorPass(), priority: -256);
        $container->addCompilerPass(new AddDoctrineDBALMonitorPass(), priority: AddDoctrineDBALMonitorPass::PRIORITY);
        $container->addCompilerPass(new SaveElasticaClientsListPass());
    }

    #[Override]
    public function boot(): void
    {
        parent::boot();

        $container = $this->container;
        if (!$container instanceof ContainerInterface) {
            return;
        }

        $this->registerMongoDbSubscriber($container);
        $this->wireElasticaTransports($container);
    }

    private function registerMongoDbSubscriber(ContainerInterface $container): void
    {
        if (!class_exists(Client::class)) {
            return;
        }

        $subscriber = $container->get(TimingSubscriber::class, ContainerInterface::NULL_ON_INVALID_REFERENCE);
        if (!$subscriber instanceof TimingSubscriber) {
            return;
        }

        addSubscriber($subscriber);
    }

    private function wireElasticaTransports(ContainerInterface $container): void
    {
        // TimingTransport hooks the Elastica 7 connection/transport API, which Elastica 8 removed.
        if (!class_exists(AbstractTransport::class)) {
            return;
        }

        try {
            $ids = $container->getParameter(SaveElasticaClientsListPass::PARAMETER);
        } catch (Throwable) {
            // SaveElasticaClientsListPass did not run (Elastica not installed) — nothing to wire.
            return;
        }

        if (!is_array($ids) || $ids === []) {
            return;
        }

        $collector = $container->get(ElasticaCollector::class, ContainerInterface::NULL_ON_INVALID_REFERENCE);
        if (!$collector instanceof ElasticaCollector) {
            return;
        }

        foreach ($ids as $id) {
            $client = is_string($id) ? $container->get($id, ContainerInterface::NULL_ON_INVALID_REFERENCE) : null;
            if (!$client instanceof ElasticaClient) {
                continue;
            }

            foreach ($client->getConnections() as $connection) {
                // Elastica 7 phpdoc narrows setTransport() to array|string; setParam() takes the
                // transport object that AbstractTransport::create() accepts at runtime.
                $connection->setParam(
                    'transport',
                    new TimingTransport()->init($connection->getTransportObject(), $collector),
                );
            }
        }
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    private function section(array $config, string $name): array
    {
        $section = $config[$name] ?? null;
        if (!\is_array($section)) {
            throw new LogicException(\sprintf('Option "%s" must be processed by configure() before loading.', $name));
        }

        return $section;
    }
}
