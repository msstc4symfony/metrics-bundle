<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\DependencyInjection\CompilerPass;

use Msstc4Symfony\MetricsBundle\DependencyInjection\CompilerPass\InjectHttpCachePass;
use Msstc4Symfony\MetricsBundle\Presentation\EventListener\RequestEventListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class InjectHttpCachePassTest extends TestCase
{
    public function testEnabledHttpCacheIsInjected(): void
    {
        $container = $this->containerWithListener();
        $container->register('http_cache')->setPublic(true);

        (new InjectHttpCachePass())->process($container);

        $argument = $container->getDefinition(RequestEventListener::class)->getArgument('$httpCache');
        self::assertInstanceOf(Reference::class, $argument);
        self::assertSame('http_cache', (string) $argument);
    }

    public function testDisabledHttpCacheIsNotInjected(): void
    {
        // FrameworkExtension keeps the definition but leaves it private when framework.http_cache is disabled.
        $container = $this->containerWithListener();
        $container->register('http_cache')->setPublic(false);

        (new InjectHttpCachePass())->process($container);

        self::assertNull($container->getDefinition(RequestEventListener::class)->getArgument('$httpCache'));
    }

    public function testMissingHttpCacheIsNotInjected(): void
    {
        $container = $this->containerWithListener();

        (new InjectHttpCachePass())->process($container);

        self::assertNull($container->getDefinition(RequestEventListener::class)->getArgument('$httpCache'));
    }

    public function testWithoutTheListenerNothingIsRegistered(): void
    {
        $container = new ContainerBuilder();
        $container->register('http_cache')->setPublic(true);

        (new InjectHttpCachePass())->process($container);

        self::assertFalse($container->hasDefinition(RequestEventListener::class));
    }

    private function containerWithListener(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(RequestEventListener::class, RequestEventListener::class);

        return $container;
    }
}
