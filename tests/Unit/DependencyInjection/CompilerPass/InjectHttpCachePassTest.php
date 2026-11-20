<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\DependencyInjection\CompilerPass;

use Msstc4Symfony\MetricsBundle\DependencyInjection\CompilerPass\InjectHttpCachePass;
use Msstc4Symfony\MetricsBundle\Presentation\EventListener\HttpCacheContext;
use Msstc4Symfony\MetricsBundle\Test\Support\SubclassedHttpCache;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\Compiler\DecoratorServicePass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\HttpCache\HttpCache;

final class InjectHttpCachePassTest extends TestCase
{
    public function testEnabledHttpCacheIsInjected(): void
    {
        $container = $this->containerWithContext();
        $container->register('http_cache', HttpCache::class)->setPublic(true);

        (new InjectHttpCachePass())->process($container);

        $argument = $this->injected($container);
        self::assertInstanceOf(Reference::class, $argument);
        self::assertSame('http_cache', (string) $argument);
    }

    public function testHttpCacheSubclassIsInjected(): void
    {
        $container = $this->containerWithContext();
        $container->register('http_cache', SubclassedHttpCache::class)->setPublic(true);

        (new InjectHttpCachePass())->process($container);

        self::assertInstanceOf(Reference::class, $this->injected($container));
    }

    public function testClassGivenThroughAParameterIsResolved(): void
    {
        $container = $this->containerWithContext();
        $container->setParameter('http_cache.class', HttpCache::class);
        $container->register('http_cache', '%http_cache.class%')->setPublic(true);

        (new InjectHttpCachePass())->process($container);

        self::assertInstanceOf(Reference::class, $this->injected($container));
    }

    public function testPublicServiceThatIsNotAnHttpCacheIsNotInjected(): void
    {
        $container = $this->containerWithContext();
        $container->register('http_cache', stdClass::class)->setPublic(true);

        (new InjectHttpCachePass())->process($container);

        self::assertNull($this->injected($container));
    }

    public function testPublicServiceWithoutAClassIsNotInjected(): void
    {
        $container = $this->containerWithContext();
        $container->register('http_cache')->setPublic(true);

        (new InjectHttpCachePass())->process($container);

        self::assertNull($this->injected($container));
    }

    public function testHttpCacheDecoratedByAnotherKernelIsNotInjected(): void
    {
        $container = $this->containerWithContext();
        $container->register('http_cache', HttpCache::class)->setPublic(true);
        $container->register('app.wrapper', stdClass::class)->setDecoratedService('http_cache');
        (new DecoratorServicePass())->process($container);

        (new InjectHttpCachePass())->process($container);

        self::assertNull($this->injected($container));
    }

    public function testHttpCacheDecoratedByAnotherHttpCacheIsInjected(): void
    {
        $container = $this->containerWithContext();
        $container->register('http_cache', HttpCache::class)->setPublic(true);
        $container->register('app.wrapper', SubclassedHttpCache::class)->setDecoratedService('http_cache');
        (new DecoratorServicePass())->process($container);

        (new InjectHttpCachePass())->process($container);

        self::assertInstanceOf(Reference::class, $this->injected($container));
    }

    public function testDisabledHttpCacheIsNotInjected(): void
    {
        // FrameworkExtension keeps the definition but leaves it private when framework.http_cache is disabled.
        $container = $this->containerWithContext();
        $container->register('http_cache', HttpCache::class)->setPublic(false);

        (new InjectHttpCachePass())->process($container);

        self::assertNull($this->injected($container));
    }

    public function testMissingHttpCacheIsNotInjected(): void
    {
        $container = $this->containerWithContext();

        (new InjectHttpCachePass())->process($container);

        self::assertNull($this->injected($container));
    }

    public function testWithoutTheContextNothingIsRegistered(): void
    {
        $container = new ContainerBuilder();
        $container->register('http_cache', HttpCache::class)->setPublic(true);

        (new InjectHttpCachePass())->process($container);

        self::assertFalse($container->hasDefinition(HttpCacheContext::class));
    }

    private function injected(ContainerBuilder $container): mixed
    {
        return $container->getDefinition(HttpCacheContext::class)->getArgument(0);
    }

    private function containerWithContext(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(HttpCacheContext::class, HttpCacheContext::class);

        return $container;
    }
}
