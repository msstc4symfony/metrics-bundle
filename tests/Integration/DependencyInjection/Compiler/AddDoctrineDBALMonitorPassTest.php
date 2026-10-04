<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\DependencyInjection\Compiler;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\MiddlewaresPass;
use Doctrine\DBAL\Driver\Middleware as DriverMiddleware;
use Msstc4Symfony\MetricsBundle\DependencyInjection\Compiler\AddDoctrineDBALMonitorPass;
use Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics\NamedConnectionMiddleware;
use Msstc4Symfony\MetricsBundle\MetricsBundle;
use Override;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class AddDoctrineDBALMonitorPassTest extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        if (!interface_exists(DriverMiddleware::class)) {
            self::markTestSkipped('doctrine/dbal not installed');
        }
    }

    public function testRegistersANamedMiddlewarePerDoctrineBundleConnection(): void
    {
        $container = $this->containerWithDoctrineConnections('default', 'reports');

        new AddDoctrineDBALMonitorPass()->process($container);

        foreach (['default', 'reports'] as $name) {
            $definition = $container->getDefinition(AddDoctrineDBALMonitorPass::MIDDLEWARE_ID_PREFIX . $name);
            self::assertSame(NamedConnectionMiddleware::class, $definition->getClass());
            self::assertTrue($definition->isAutowired());
            self::assertSame($name, $definition->getArgument('$connectionName'));
            self::assertSame([['connection' => $name]], $definition->getTag(AddDoctrineDBALMonitorPass::MIDDLEWARE_TAG));
        }
    }

    public function testRegistersNothingWithoutDoctrineBundleConnections(): void
    {
        $container = new ContainerBuilder();
        $before = array_keys($container->getDefinitions());

        new AddDoctrineDBALMonitorPass()->process($container);

        self::assertSame($before, array_keys($container->getDefinitions()));
    }

    public function testKeepsAnApplicationDefinedMiddleware(): void
    {
        $container = $this->containerWithDoctrineConnections('default');
        $custom = new Definition(NamedConnectionMiddleware::class);
        $container->setDefinition(AddDoctrineDBALMonitorPass::MIDDLEWARE_ID_PREFIX . 'default', $custom);

        new AddDoctrineDBALMonitorPass()->process($container);

        self::assertSame($custom, $container->getDefinition(AddDoctrineDBALMonitorPass::MIDDLEWARE_ID_PREFIX . 'default'));
    }

    public function testDoctrineBundleAppliesEachMiddlewareToItsOwnConnectionOnly(): void
    {
        if (!class_exists(MiddlewaresPass::class)) {
            self::markTestSkipped('doctrine/doctrine-bundle not installed');
        }

        $container = $this->containerWithDoctrineConnections('default', 'reports');

        new AddDoctrineDBALMonitorPass()->process($container);
        new MiddlewaresPass()->process($container);

        foreach (['default', 'reports'] as $name) {
            $middleware = AddDoctrineDBALMonitorPass::MIDDLEWARE_ID_PREFIX . $name;
            self::assertEquals(
                [['setMiddlewares', [[new Reference($middleware . '.' . $name)]]]],
                $container->getDefinition(\sprintf('doctrine.dbal.%s_connection.configuration', $name))->getMethodCalls(),
            );
        }
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

    private function containerWithDoctrineConnections(string ...$names): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $connections = [];
        foreach ($names as $name) {
            $connections[$name] = \sprintf('doctrine.dbal.%s_connection', $name);
            $container->register(\sprintf('doctrine.dbal.%s_connection.configuration', $name));
        }

        $container->setParameter('doctrine.connections', $connections);

        return $container;
    }
}
