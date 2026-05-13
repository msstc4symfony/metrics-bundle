<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle;

use MaxShamaev\MetricsBundle\DependencyInjection\Compiler\AddDoctrineDBALMonitorPass;
use MaxShamaev\MetricsBundle\DependencyInjection\Compiler\AddHttpClientMonitorPass;
use MaxShamaev\MetricsBundle\DependencyInjection\Compiler\AddMonologDecoratorCompilerPass;
use MaxShamaev\MetricsBundle\DependencyInjection\Compiler\SaveElasticaClientsListPass;
use MaxShamaev\MetricsBundle\DependencyInjection\MetricsExtension;
use MaxShamaev\MetricsBundle\Infrastructure\Collector\ElasticaCollector;
use MaxShamaev\MetricsBundle\Infrastructure\Doctrine\ODM\Metrics\TimingSubscriber;
use MaxShamaev\MetricsBundle\Infrastructure\Elastica\TimingTransport;
use MongoDB\Client;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Throwable;

use function MongoDB\Driver\Monitoring\addSubscriber;

final class MetricsBundle extends Bundle
{
    public function getContainerExtension(): ExtensionInterface
    {
        return new MetricsExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new AddMonologDecoratorCompilerPass());
        $container->addCompilerPass(new AddHttpClientMonitorPass(), priority: -256);
        $container->addCompilerPass(new AddDoctrineDBALMonitorPass());
        $container->addCompilerPass(new SaveElasticaClientsListPass());
    }

    public function boot(): void
    {
        parent::boot();

        if (!$this->container instanceof ContainerInterface) {
            return;
        }

        // Add MongoDB timing subscriber
        if (class_exists(Client::class)) {
            /** @var ?TimingSubscriber $subscriber */
            $subscriber = $this->container->get(TimingSubscriber::class, $this->container::NULL_ON_INVALID_REFERENCE);
            if ($subscriber !== null) {
                addSubscriber($subscriber);
            }
        }

        // Add Elastica transport
        /** @var string[]|null $ids */
        $ids = [];
        try {
            $ids = $this->container->getParameter('metrics.elastica.clients');
        } catch (Throwable) {
        }
        if (is_array($ids) && $ids !== []) {
            /** @var ?ElasticaCollector $collector */
            $collector = $this->container->get(ElasticaCollector::class, $this->container::NULL_ON_INVALID_REFERENCE);
            if ($collector !== null) {
                /** @var string $id */
                foreach ($ids as $id) {
                    $client = $this->container->get($id);

                    if (!$client instanceof \Elastica\Client) {
                        continue;
                    }

                    // ruflin/elastica 1.x-7.x
                    if (method_exists($client, 'getConnections')) {
                        /** @var \Elastica\Connection $connection */
                        foreach ($client->getConnections() as $connection) {
                            if (method_exists($connection, 'setTransport')) {
                                $connection->setTransport(
                                    (new TimingTransport())->init($connection->getTransportObject(), $collector),
                                );
                            }
                        }
                    }
                }
            }
        }
    }
}
