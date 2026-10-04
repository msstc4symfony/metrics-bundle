<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\DependencyInjection\Compiler;

use Doctrine\DBAL\Driver\Middleware as DriverMiddleware;
use Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics\NamedConnectionMiddleware;
use Override;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Registers one NamedConnectionMiddleware per DoctrineBundle connection, tagged for that
 * connection only, so DoctrineBundle's MiddlewaresPass gives each connection a middleware
 * that labels queries with its name. Without DoctrineBundle nothing is registered:
 * applications wire NamedConnectionMiddleware into their connections by hand.
 */
final class AddDoctrineDBALMonitorPass implements CompilerPassInterface
{
    // Must run before DoctrineBundle's MiddlewaresPass (priority 0) regardless of bundle order.
    public const int PRIORITY = 1;

    public const string MIDDLEWARE_TAG = 'doctrine.middleware';

    public const string MIDDLEWARE_ID_PREFIX = 'msstc4symfony_metrics.doctrine.middleware.';

    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (!interface_exists(DriverMiddleware::class) || !$container->hasParameter('doctrine.connections')) {
            return;
        }

        $connections = $container->getParameter('doctrine.connections');
        if (!\is_array($connections)) {
            return;
        }

        foreach (array_keys($connections) as $name) {
            $name = (string) $name;
            $id = self::MIDDLEWARE_ID_PREFIX . $name;
            if ($container->hasDefinition($id)) {
                continue;
            }

            $container->register($id, NamedConnectionMiddleware::class)
                ->setAutowired(true)
                ->setArgument('$connectionName', $name)
                ->addTag(self::MIDDLEWARE_TAG, ['connection' => $name])
            ;
        }
    }
}
