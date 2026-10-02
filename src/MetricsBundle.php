<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle;

use Elastica\Client as ElasticaClient;
use Elastica\Transport\AbstractTransport;
use MongoDB\Client;
use Msstc4Symfony\MetricsBundle\DependencyInjection\Compiler\AddDoctrineDBALMonitorPass;
use Msstc4Symfony\MetricsBundle\DependencyInjection\Compiler\AddHttpClientMonitorPass;
use Msstc4Symfony\MetricsBundle\DependencyInjection\Compiler\AddMonologDecoratorCompilerPass;
use Msstc4Symfony\MetricsBundle\DependencyInjection\Compiler\RegisterMessengerMetricsPass;
use Msstc4Symfony\MetricsBundle\DependencyInjection\Compiler\SaveElasticaClientsListPass;
use Msstc4Symfony\MetricsBundle\DependencyInjection\MetricsExtension;
use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\ElasticaCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\ODM\Metrics\TimingSubscriber;
use Msstc4Symfony\MetricsBundle\Infrastructure\Elastica\TimingTransport;
use Override;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Throwable;

use function MongoDB\Driver\Monitoring\addSubscriber;

final class MetricsBundle extends Bundle
{
    #[Override]
    public function getContainerExtension(): ExtensionInterface
    {
        return new MetricsExtension();
    }

    #[Override]
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new AddMonologDecoratorCompilerPass());
        $container->addCompilerPass(new AddHttpClientMonitorPass(), priority: -256);
        $container->addCompilerPass(new AddDoctrineDBALMonitorPass(), priority: AddDoctrineDBALMonitorPass::PRIORITY);
        $container->addCompilerPass(new SaveElasticaClientsListPass());
        $container->addCompilerPass(new RegisterMessengerMetricsPass());
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
            $ids = $container->getParameter('metrics.elastica.clients');
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
}
