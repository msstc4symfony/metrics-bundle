<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\DependencyInjection;

use Elastica\Client as ElasticaClient;
use Elastica\Transport\AbstractTransport;
use MongoDB\Client;
use Msstc4Symfony\MetricsBundle\Application\Collector\ElasticaCollector;
use Msstc4Symfony\MetricsBundle\DependencyInjection\CompilerPass\DecorateElasticaClientsPass;
use Msstc4Symfony\MetricsBundle\DependencyInjection\CompilerPass\SaveElasticaClientsListPass;
use Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\ODM\Metrics\TimingSubscriber;
use Msstc4Symfony\MetricsBundle\Infrastructure\Elastica\TimingTransport;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Throwable;

use function MongoDB\Driver\Monitoring\addSubscriber;

/**
 * Integrations that need the booted container: the MongoDB driver subscriber and Elastica 7 transports.
 *
 * @internal
 */
final readonly class RuntimeIntegrations
{
    public static function register(ContainerInterface $container): void
    {
        self::registerMongoDbSubscriber($container);
        self::wireElasticaTransports($container);
    }

    private static function registerMongoDbSubscriber(ContainerInterface $container): void
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

    private static function wireElasticaTransports(ContainerInterface $container): void
    {
        // TimingTransport hooks the Elastica 7 connection/transport API, which Elastica 8 and 9 do not have.
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

        $sanitizePath = !$container->hasParameter(DecorateElasticaClientsPass::SANITIZE_PATH_PARAMETER) || $container->getParameter(DecorateElasticaClientsPass::SANITIZE_PATH_PARAMETER) !== false;

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
                    (new TimingTransport())->init($connection->getTransportObject(), $collector, $sanitizePath),
                );
            }
        }
    }
}
