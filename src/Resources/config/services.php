<?php

declare(strict_types=1);

use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use MongoDB\Driver\Monitoring\CommandSubscriber;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricRepository;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricRepositoryFactory;
use Msstc4Symfony\MetricsBundle\Application\Port\MetricBatchRecorderInterface;
use Msstc4Symfony\MetricsBundle\Application\Port\MetricRecorderInterface;
use Msstc4Symfony\MetricsBundle\Application\Port\MetricStorageInterface;
use Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\ODM\Metrics\TimingSubscriber;
use Msstc4Symfony\MetricsBundle\Infrastructure\Guzzle\MetricsGuzzleMiddleware;
use Msstc4Symfony\MetricsBundle\Infrastructure\Prometheus\PrometheusMetricRecorder;
use Msstc4Symfony\MetricsBundle\Infrastructure\Prometheus\PrometheusMetricStorage;
use Msstc4Symfony\MetricsBundle\Infrastructure\Prometheus\Storage\FactoryInterface;
use Msstc4Symfony\MetricsBundle\Presentation\EventListener\MessengerEventListener;
use Prometheus\CollectorRegistry;
use Prometheus\RegistryInterface;
use Prometheus\RendererInterface;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\Adapter;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $services->defaults()
        ->autowire()
        ->autoconfigure()
        ->bind('$applicationName', param('msstc4symfony_metrics.application_name'))
        ->bind('$componentName', param('msstc4symfony_metrics.component_name'))
    ;

    // Classes that need an optional library stay out of the scan: symfony/dependency-injection 7.4.0
    // loads every scanned class and fatals on a missing interface. They are registered below, guarded.
    $services->load('Msstc4Symfony\MetricsBundle\\', '../../')
        ->exclude([
            '../../DependencyInjection/',
            '../../Presentation/EventListener/MessengerEventListener.php',
            // Registered by RegisterMessengerQueueDepthPass when msstc4symfony_metrics.messenger.queue_depth is enabled.
            '../../Presentation/EventListener/MessengerQueueDepthListener.php',
            '../../Infrastructure/Messenger/',
            // Plain helpers, never services.
            '../../Presentation/EventListener/QueueDepthSamplingState.php',
            '../../Infrastructure/OutgoingHttp/PathSanitizer.php',
            // DecorateCachePoolsPass registers these only for listed pools.
            '../../Infrastructure/Cache/',
            '../../Application/Collector/CacheCollector.php',
            '../../Presentation/EventListener/CacheMetricsFlushListener.php',
            '../../Infrastructure/Doctrine/',
            // URLAssembler/ stays scanned: AssemblerInterface carries the #[AutoconfigureTag] for application assemblers.
            '../../Infrastructure/HttpClient/*.php',
            '../../Infrastructure/Elastica/',
            '../../Infrastructure/Guzzle/',
            '../../Framework/Monolog/',
            '../../Resources/',
            '../../MetricsBundle.php',
        ])
    ;

    if (class_exists(WorkerMessageReceivedEvent::class)) {
        $services->set(MessengerEventListener::class);
    }

    // Registered here, before the optimization passes, so it is autowired; AddGuzzleMonitorPass adds it to clients.
    if (interface_exists(GuzzleClientInterface::class)) {
        $services->set(MetricsGuzzleMiddleware::class);
    }

    if (interface_exists(CommandSubscriber::class)) {
        $services->set(TimingSubscriber::class);
    }

    $services->set(MetricRepository::class)
        ->factory([service(MetricRepositoryFactory::class), 'create'])
    ;

    $services->set(Adapter::class)
        ->factory([service(FactoryInterface::class), 'create'])
        ->arg('$dsn', param('msstc4symfony_metrics.storage.dsn'))
    ;

    $services->set(RegistryInterface::class, CollectorRegistry::class)
        ->arg('$registerDefaultMetrics', false)
    ;

    $services->set(RendererInterface::class, RenderTextFormat::class);

    $services->alias(MetricRecorderInterface::class, PrometheusMetricRecorder::class);
    $services->alias(MetricBatchRecorderInterface::class, PrometheusMetricRecorder::class);
    $services->alias(MetricStorageInterface::class, PrometheusMetricStorage::class);
};
