<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\DependencyInjection\CompilerPass;

use Msstc4Symfony\MetricsBundle\Presentation\EventListener\HttpCacheContext;
use Override;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\HttpCache\HttpCache;

/**
 * Gives HttpCacheContext the http_cache service only when Kernel::handle() routes main requests through an HttpCache:
 * FrameworkExtension makes the service public exactly when framework.http_cache is enabled, and an application may
 * decorate it with something else. Otherwise the private, unused service is removed instead of being created (its Store
 * creates a directory).
 *
 * @internal
 */
final class InjectHttpCachePass implements CompilerPassInterface
{
    private const HTTP_CACHE = 'http_cache';

    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(HttpCacheContext::class)) {
            return;
        }

        $httpCache = $this->isServedThroughHttpCache($container) ? new Reference(self::HTTP_CACHE) : null;
        // By index: autowiring has already filled it, and a named argument would be passed on top of it.
        $container->getDefinition(HttpCacheContext::class)->setArgument(0, $httpCache);
    }

    // Runs before removing: DecoratorServicePass has already turned the http_cache id into an alias of the decorator.
    private function isServedThroughHttpCache(ContainerBuilder $container): bool
    {
        $isPublic = match (true) {
            $container->hasAlias(self::HTTP_CACHE) => $container->getAlias(self::HTTP_CACHE)->isPublic(),
            $container->hasDefinition(self::HTTP_CACHE) => $container->getDefinition(self::HTTP_CACHE)->isPublic(),
            default => false,
        };
        if (!$isPublic) {
            return false;
        }

        $class = $container->findDefinition(self::HTTP_CACHE)->getClass();
        $resolved = \is_string($class) ? $container->getParameterBag()->resolveValue($class) : null;

        return \is_string($resolved) && is_a($resolved, HttpCache::class, true);
    }
}
