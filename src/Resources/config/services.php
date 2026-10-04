<?php

declare(strict_types=1);

use MongoDB\Driver\Monitoring\CommandSubscriber;
use Msstc4Symfony\MetricsBundle\Framework\EventListener\MessengerEventListener;
use Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\ODM\Metrics\TimingSubscriber;
use Msstc4Symfony\MetricsBundle\Infrastructure\Factory\MetricRepositoryFactory;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepository;
use Msstc4Symfony\MetricsBundle\Infrastructure\Storage\FactoryInterface;
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
            '../../Framework/EventListener/MessengerEventListener.php',
            '../../Infrastructure/Doctrine/',
            // URLAssembler/ stays scanned: AssemblerInterface carries the #[AutoconfigureTag] for application assemblers.
            '../../Infrastructure/HttpClient/*.php',
            '../../Infrastructure/Elastica/',
            '../../Infrastructure/Monolog/',
            '../../Resources/',
            '../../MetricsBundle.php',
        ])
    ;

    if (class_exists(WorkerMessageReceivedEvent::class)) {
        $services->set(MessengerEventListener::class);
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
};
