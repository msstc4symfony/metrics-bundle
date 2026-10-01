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
 * tagged whenever DoctrineBundle has configured DBAL connections. Without DoctrineBundle
 * it is registered untagged for applications that wire it into their connections by hand.
 */
final class AddDoctrineDBALMonitorPass implements CompilerPassInterface
{
    // Must run before DoctrineBundle's MiddlewaresPass (priority 0) regardless of bundle order.
    public const int PRIORITY = 1;

    public const string MIDDLEWARE_TAG = 'doctrine.middleware';

    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (!interface_exists(DriverMiddleware::class) || $container->hasDefinition(Middleware::class)) {
            return;
        }

        $definition = $container->setDefinition(Middleware::class, new Definition(Middleware::class))
            ->setAutowired(true)
        ;

        if ($container->hasParameter('doctrine.connections')) {
            $definition->addTag(self::MIDDLEWARE_TAG);
        }
    }
}
