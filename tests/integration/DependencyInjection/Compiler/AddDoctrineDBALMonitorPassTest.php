<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Test\Integration\DependencyInjection\Compiler;

use Doctrine\DBAL\Connection;
use MaxShamaev\MetricsBundle\DependencyInjection\Compiler\AddDoctrineDBALMonitorPass;
use MaxShamaev\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics\Middleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class AddDoctrineDBALMonitorPassTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Connection::class)) {
            self::markTestSkipped('doctrine/dbal not installed');
        }
    }

    public function testRegistersMiddlewareWhenDoctrineConnectionFound(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('doctrine.dbal.default_connection', new Definition(Connection::class));

        new AddDoctrineDBALMonitorPass()->process($container);

        self::assertTrue($container->hasDefinition(Middleware::class));
        self::assertTrue($container->getDefinition(Middleware::class)->isAutowired());
    }

    public function testIgnoresAbstractDefinitions(): void
    {
        $container = new ContainerBuilder();
        $abstract = new Definition(Connection::class);
        $abstract->setAbstract(true);

        $container->setDefinition('doctrine.dbal.default_connection', $abstract);

        new AddDoctrineDBALMonitorPass()->process($container);

        self::assertFalse($container->hasDefinition(Middleware::class));
    }

    public function testIgnoresIdsNotMatchingPattern(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('my.custom.dbal_thing', new Definition(Connection::class));

        new AddDoctrineDBALMonitorPass()->process($container);

        self::assertFalse($container->hasDefinition(Middleware::class));
    }

    public function testIgnoresDefinitionsWithoutClass(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('doctrine.dbal.default_connection', new Definition());

        new AddDoctrineDBALMonitorPass()->process($container);

        self::assertFalse($container->hasDefinition(Middleware::class));
    }
}
