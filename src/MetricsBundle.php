<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle;

use MongoDB\Client;
use Msstc4Symfony\MetricsBundle\DependencyInjection\Compiler\AddDoctrineDBALMonitorPass;
use Msstc4Symfony\MetricsBundle\DependencyInjection\Compiler\AddHttpClientMonitorPass;
use Msstc4Symfony\MetricsBundle\DependencyInjection\Compiler\AddMonologDecoratorCompilerPass;
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
        $container->addCompilerPass(new AddDoctrineDBALMonitorPass());
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

        /** @var ?TimingSubscriber $subscriber */
        $subscriber = $container->get(TimingSubscriber::class, ContainerInterface::NULL_ON_INVALID_REFERENCE);
        if ($subscriber === null) {
            return;
        }

        addSubscriber($subscriber);
    }

    private function wireElasticaTransports(ContainerInterface $container): void
    {
        try {
            /** @var string[]|null $ids */
            $ids = $container->getParameter('metrics.elastica.clients');
        } catch (Throwable) {
            // SaveElasticaClientsListPass did not run (Elastica not installed) — nothing to wire.
            return;
        }

        if (!is_array($ids) || $ids === []) {
            return;
        }

        /** @var ?ElasticaCollector $collector */
        $collector = $container->get(ElasticaCollector::class, ContainerInterface::NULL_ON_INVALID_REFERENCE);
        if ($collector === null) {
            return;
        }

        /** @var string $id */
        foreach ($ids as $id) {
            $client = $container->get($id);
            if (!$client instanceof \Elastica\Client || !method_exists($client, 'getConnections')) {
                continue;
            }

            /** @var \Elastica\Connection $connection */
            foreach ($client->getConnections() as $connection) {
                if (!method_exists($connection, 'setTransport')) {
                    continue;
                }

                $connection->setTransport(
                    new TimingTransport()->init($connection->getTransportObject(), $collector),
                );
            }
        }
    }
}
