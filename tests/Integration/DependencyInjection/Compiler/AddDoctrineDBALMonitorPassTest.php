<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\DependencyInjection\Compiler;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\MiddlewaresPass;
use Doctrine\DBAL\Driver\Middleware as DriverMiddleware;
use Msstc4Symfony\MetricsBundle\DependencyInjection\Compiler\AddDoctrineDBALMonitorPass;
use Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics\Middleware;
use Msstc4Symfony\MetricsBundle\MetricsBundle;
use Override;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class AddDoctrineDBALMonitorPassTest extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        if (!interface_exists(DriverMiddleware::class)) {
            self::markTestSkipped('doctrine/dbal not installed');
        }
    }

    public function testRegistersTaggedMiddlewareWhenDoctrineBundleConfiguredConnections(): void
    {
        $container = $this->containerWithDoctrineConnections();

        new AddDoctrineDBALMonitorPass()->process($container);

        self::assertTrue($container->hasDefinition(Middleware::class));
        $definition = $container->getDefinition(Middleware::class);
        self::assertTrue($definition->isAutowired());
        self::assertSame([[]], $definition->getTag(AddDoctrineDBALMonitorPass::MIDDLEWARE_TAG));
    }

    public function testRegistersUntaggedMiddlewareWithoutDoctrineBundleForHandWiredConnections(): void
    {
        $container = new ContainerBuilder();

        new AddDoctrineDBALMonitorPass()->process($container);

        self::assertTrue($container->hasDefinition(Middleware::class));
        $definition = $container->getDefinition(Middleware::class);
        self::assertTrue($definition->isAutowired());
        self::assertFalse($definition->hasTag(AddDoctrineDBALMonitorPass::MIDDLEWARE_TAG));
    }

    public function testKeepsAnApplicationDefinedMiddleware(): void
    {
        $container = $this->containerWithDoctrineConnections();
        $custom = new Definition(Middleware::class)->addTag(AddDoctrineDBALMonitorPass::MIDDLEWARE_TAG, ['connection' => 'default']);
        $container->setDefinition(Middleware::class, $custom);

        new AddDoctrineDBALMonitorPass()->process($container);

        self::assertSame($custom, $container->getDefinition(Middleware::class));
    }

    public function testRunsBeforeDoctrineMiddlewaresPassWhateverTheBundleOrder(): void
    {
        if (!class_exists(MiddlewaresPass::class)) {
            self::markTestSkipped('doctrine/doctrine-bundle not installed');
        }

        $container = new ContainerBuilder();
        $container->addCompilerPass(new MiddlewaresPass());
        new MetricsBundle()->build($container);

        $order = array_map(get_class(...), $container->getCompilerPassConfig()->getBeforeOptimizationPasses());

        self::assertLessThan(
            array_search(MiddlewaresPass::class, $order, true),
            array_search(AddDoctrineDBALMonitorPass::class, $order, true),
        );
    }

    private function containerWithDoctrineConnections(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('doctrine.connections', ['default' => 'doctrine.dbal.default_connection']);

        return $container;
    }
}
