<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\DependencyInjection\Compiler;

use Doctrine\Bundle\DoctrineBundle\Middleware\ConnectionNameAwareInterface;
use Doctrine\DBAL\Driver\Middleware as DriverMiddleware;
use Msstc4Symfony\MetricsBundle\DependencyInjection\Configuration;
use Msstc4Symfony\MetricsBundle\DependencyInjection\MetricsExtension;
use Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics\Middleware;
use Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics\NamedConnectionMiddleware;
use Override;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\LogicException;

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
        if (!interface_exists(DriverMiddleware::class)) {
            return;
        }

        if ($container->hasDefinition(Middleware::class)) {
            if ($container->hasParameter('doctrine.connections') && $this->labelsByConnectionName($container)) {
                throw new LogicException(\sprintf('metrics.doctrine.connection_label "name" cannot apply to the application-defined "%s" service: remove that definition or use "host_dbname".', Middleware::class));
            }

            return;
        }

        $definition = $container->setDefinition(Middleware::class, new Definition(Middleware::class))
            ->setAutowired(true)
        ;

        if (!$container->hasParameter('doctrine.connections')) {
            return;
        }

        if (!$this->labelsByConnectionName($container)) {
            $definition->addTag(self::MIDDLEWARE_TAG);

            return;
        }

        if (!interface_exists(ConnectionNameAwareInterface::class)) {
            throw new LogicException('metrics.doctrine.connection_label "name" requires a doctrine/doctrine-bundle version with ConnectionNameAwareInterface.');
        }

        $container->setDefinition(NamedConnectionMiddleware::class, new Definition(NamedConnectionMiddleware::class))
            ->setAutowired(true)
            ->addTag(self::MIDDLEWARE_TAG)
        ;
    }

    private function labelsByConnectionName(ContainerBuilder $container): bool
    {
        return $container->hasParameter(MetricsExtension::DOCTRINE_CONNECTION_LABEL_PARAMETER)
            && $container->getParameter(MetricsExtension::DOCTRINE_CONNECTION_LABEL_PARAMETER) === Configuration::CONNECTION_LABEL_NAME;
    }
}
