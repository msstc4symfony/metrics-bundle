<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\DependencyInjection\Compiler;

use Monolog\Logger;
use Msstc4Symfony\MetricsBundle\DependencyInjection\Compiler\AddMonologDecoratorCompilerPass;
use Msstc4Symfony\MetricsBundle\Infrastructure\Monolog\Handler\HandlerDecorator;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class AddMonologDecoratorCompilerPassTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Logger::class)) {
            self::markTestSkipped('monolog/monolog not installed');
        }
    }

    public function testDecoratesMonologLoggerChannels(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('monolog.logger.app', new Definition(Logger::class));

        new AddMonologDecoratorCompilerPass()->process($container);

        self::assertTrue($container->hasDefinition('monolog.logger.app.decorator.metrics'));
        $decorator = $container->getDefinition('monolog.logger.app.decorator.metrics');
        self::assertSame(HandlerDecorator::class, $decorator->getClass());
    }

    public function testSkipsExcludedChannels(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('monolog.logger.profiling', new Definition(Logger::class));
        $container->setDefinition('monolog.logger.removal_request', new Definition(Logger::class));
        $container->setDefinition('monolog.logger.deprecation', new Definition(Logger::class));

        new AddMonologDecoratorCompilerPass()->process($container);

        self::assertFalse($container->hasDefinition('monolog.logger.profiling.decorator.metrics'));
        self::assertFalse($container->hasDefinition('monolog.logger.removal_request.decorator.metrics'));
        self::assertFalse($container->hasDefinition('monolog.logger.deprecation.decorator.metrics'));
    }

    public function testIgnoresNonMonologDefinitions(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('app.service', new Definition(stdClass::class));

        new AddMonologDecoratorCompilerPass()->process($container);

        self::assertFalse($container->hasDefinition('app.service.decorator.metrics'));
    }

    public function testSkipsHandlerDecoratorItself(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('monolog.logger.app.decorator.metrics', new Definition(HandlerDecorator::class));

        new AddMonologDecoratorCompilerPass()->process($container);

        self::assertFalse($container->hasDefinition('monolog.logger.app.decorator.metrics.decorator.metrics'));
    }
}
