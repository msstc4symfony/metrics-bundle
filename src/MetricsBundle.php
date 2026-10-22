<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle;

use LogicException;
use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricDefinitionInterface;
use Msstc4Symfony\MetricsBundle\DependencyInjection\CompilerPass\AddDoctrineDBALMonitorPass;
use Msstc4Symfony\MetricsBundle\DependencyInjection\CompilerPass\AddHttpClientMonitorPass;
use Msstc4Symfony\MetricsBundle\DependencyInjection\CompilerPass\AddMonologDecoratorCompilerPass;
use Msstc4Symfony\MetricsBundle\DependencyInjection\CompilerPass\DecorateElasticaClientsPass;
use Msstc4Symfony\MetricsBundle\DependencyInjection\CompilerPass\SaveElasticaClientsListPass;
use Msstc4Symfony\MetricsBundle\DependencyInjection\RuntimeIntegrations;
use Override;
use ReflectionEnum;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class MetricsBundle extends AbstractBundle
{
    private const PARAMETER_PREFIX = 'msstc4symfony_metrics.';

    private const DEFAULT_DSN = 'redis://127.0.0.1:6379';

    private const UNKNOWN = 'unknown';

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
                ->arrayNode('elastica')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('sanitize_path')
                            ->info('Replace document ids and other identifiers in Elastica request paths with placeholders to bound the "path" label.')
                            ->defaultTrue()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('metric_enums')
                    ->info("Extra metric catalogs, appended after the bundle's own.")
                    ->scalarPrototype()
                        ->validate()
                            ->ifTrue(static fn (mixed $class): bool => !self::isStringBackedMetricEnum($class))
                            ->thenInvalid('%s is not a string-backed enum implementing ' . MetricDefinitionInterface::class . '.')
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
            ->set(DecorateElasticaClientsPass::SANITIZE_PATH_PARAMETER, $this->section($config, 'elastica')['sanitize_path'] ?? null)
            ->set(self::PARAMETER_PREFIX . 'metric_enums', [BuiltInMetric::class, ...array_values($this->section($config, 'metric_enums'))])
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
        $container->addCompilerPass(new DecorateElasticaClientsPass());
    }

    #[Override]
    public function boot(): void
    {
        parent::boot();

        $container = $this->container;
        if (!$container instanceof ContainerInterface) {
            return;
        }

        RuntimeIntegrations::register($container);
    }

    private static function isStringBackedMetricEnum(mixed $class): bool
    {
        // MetricDefinitionInterface extends BackedEnum, so only an enum gets past is_a().
        return \is_string($class)
            && is_a($class, MetricDefinitionInterface::class, true)
            && (string) (new ReflectionEnum($class))->getBackingType() === 'string';
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
