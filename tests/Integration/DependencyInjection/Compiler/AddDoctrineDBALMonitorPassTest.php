<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\DependencyInjection\Compiler;

use Composer\Autoload\ClassLoader;
use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\MiddlewaresPass;
use Doctrine\Bundle\DoctrineBundle\Middleware\ConnectionNameAwareInterface;
use Doctrine\DBAL\Driver\Middleware as DriverMiddleware;
use Msstc4Symfony\MetricsBundle\DependencyInjection\Compiler\AddDoctrineDBALMonitorPass;
use Msstc4Symfony\MetricsBundle\DependencyInjection\MetricsExtension;
use Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics\Middleware;
use Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics\NamedConnectionMiddleware;
use Msstc4Symfony\MetricsBundle\MetricsBundle;
use Override;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\LogicException;

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

    public function testTagsTheConnectionNameAwareMiddlewareWhenLabellingByConnectionName(): void
    {
        if (!interface_exists(ConnectionNameAwareInterface::class)) {
            self::markTestSkipped('doctrine/doctrine-bundle not installed');
        }

        $container = $this->containerWithDoctrineConnections();
        $container->setParameter(MetricsExtension::DOCTRINE_CONNECTION_LABEL_PARAMETER, 'name');

        new AddDoctrineDBALMonitorPass()->process($container);

        $named = $container->getDefinition(NamedConnectionMiddleware::class);
        self::assertTrue($named->isAutowired());
        self::assertSame([[]], $named->getTag(AddDoctrineDBALMonitorPass::MIDDLEWARE_TAG));
        self::assertFalse($container->getDefinition(Middleware::class)->hasTag(AddDoctrineDBALMonitorPass::MIDDLEWARE_TAG));
    }

    public function testLabelByConnectionNameWithoutDoctrineConnectionsKeepsTheUntaggedMiddleware(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(MetricsExtension::DOCTRINE_CONNECTION_LABEL_PARAMETER, 'name');

        new AddDoctrineDBALMonitorPass()->process($container);

        self::assertTrue($container->hasDefinition(Middleware::class));
        self::assertFalse($container->hasDefinition(NamedConnectionMiddleware::class));
    }

    public function testLabelByConnectionNameRejectsAnApplicationDefinedMiddleware(): void
    {
        $container = $this->containerWithDoctrineConnections();
        $container->setParameter(MetricsExtension::DOCTRINE_CONNECTION_LABEL_PARAMETER, 'name');
        $container->setDefinition(Middleware::class, new Definition(Middleware::class));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('connection_label');

        new AddDoctrineDBALMonitorPass()->process($container);
    }

    #[RunInSeparateProcess]
    public function testLabelByConnectionNameRequiresADoctrineBundleWithConnectionNameAwareInterface(): void
    {
        if (!class_exists(MiddlewaresPass::class)) {
            self::markTestSkipped('doctrine/doctrine-bundle not installed');
        }

        // Emulates a DoctrineBundle older than ConnectionNameAwareInterface: a class-map entry to an
        // empty file wins over PSR-4, so the interface never loads in this process.
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $loader->addClassMap([ConnectionNameAwareInterface::class => '/dev/null']);
        }

        $container = $this->containerWithDoctrineConnections();
        $container->setParameter(MetricsExtension::DOCTRINE_CONNECTION_LABEL_PARAMETER, 'name');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires a doctrine/doctrine-bundle version with ConnectionNameAwareInterface');

        new AddDoctrineDBALMonitorPass()->process($container);
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
