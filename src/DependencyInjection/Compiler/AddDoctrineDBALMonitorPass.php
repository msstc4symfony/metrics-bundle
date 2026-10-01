<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\DependencyInjection\Compiler;

use Doctrine\DBAL\Driver\Middleware as DriverMiddleware;
use Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics\Middleware;
use Override;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * DoctrineBundle defines connections as class-less child definitions and applies only
 * services tagged "doctrine.middleware" (its MiddlewaresPass), so the middleware is
 * registered with that tag whenever DoctrineBundle has configured DBAL connections.
 */
final class AddDoctrineDBALMonitorPass implements CompilerPassInterface
{
    // Must run before DoctrineBundle's MiddlewaresPass (priority 0) regardless of bundle order.
    public const int PRIORITY = 1;

    public const string MIDDLEWARE_TAG = 'doctrine.middleware';

    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (
            !interface_exists(DriverMiddleware::class)
            || !$container->hasParameter('doctrine.connections')
            || $container->hasDefinition(Middleware::class)
        ) {
            return;
        }

        $container->setDefinition(Middleware::class, new Definition(Middleware::class))
            ->setAutowired(true)
            ->addTag(self::MIDDLEWARE_TAG)
        ;
    }
}
