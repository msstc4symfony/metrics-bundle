<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\DependencyInjection\CompilerPass;

use Msstc4Symfony\MetricsBundle\Presentation\EventListener\RequestEventListener;
use Override;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Gives RequestEventListener the http_cache service only when Kernel::handle() routes main requests through it:
 * FrameworkExtension makes the service public exactly when framework.http_cache is enabled. Otherwise the private,
 * unused service is removed instead of being created (its Store creates a directory).
 *
 * @internal
 */
final class InjectHttpCachePass implements CompilerPassInterface
{
    private const HTTP_CACHE = 'http_cache';

    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(RequestEventListener::class)) {
            return;
        }

        $enabled = $container->hasDefinition(self::HTTP_CACHE) && $container->getDefinition(self::HTTP_CACHE)->isPublic();

        $container->getDefinition(RequestEventListener::class)
            ->setArgument('$httpCache', $enabled ? new Reference(self::HTTP_CACHE) : null)
        ;
    }
}
