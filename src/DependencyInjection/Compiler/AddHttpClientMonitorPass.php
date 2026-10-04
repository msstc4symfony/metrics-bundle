<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\DependencyInjection\Compiler;

use Msstc4Symfony\MetricsBundle\Infrastructure\HttpClient\HttpClientDecorator;
use Msstc4Symfony\MetricsBundle\Infrastructure\HttpClient\URLAssembler\AssemblerInterface;
use Override;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\Response\AsyncResponse;

/**
 * Every FrameworkBundle client, default and scoped, ends in the shared transport with an
 * absolute URL. Monitoring that single point counts each real request exactly once;
 * decorating the client services as well would count it twice and see scoped clients
 * before their base_uri is applied.
 */
final class AddHttpClientMonitorPass implements CompilerPassInterface
{
    public const string TRANSPORT_ID = 'http_client.transport';

    public const string DECORATOR_ID = self::TRANSPORT_ID . '.decorator.monitor';

    // Below FrameworkBundle's mock_response_factory decorator (-10): a lower priority is applied
    // later, i.e. further out, so functional tests with a mocked transport still see metrics.
    private const int DECORATION_PRIORITY = -20;

    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (!class_exists(AsyncResponse::class) || !$container->hasDefinition(self::TRANSPORT_ID)) {
            return;
        }

        if (!DefinitionFilter::isDecoratable($container->getDefinition(self::TRANSPORT_ID))) {
            return;
        }

        $container->register(self::DECORATOR_ID, HttpClientDecorator::class)
            ->setAutowired(true)
            ->setArgument('$inner', new Reference(self::DECORATOR_ID . '.inner'))
            ->setArgument('$urlAssemblers', new TaggedIteratorArgument(AssemblerInterface::TAG))
            ->setDecoratedService(self::TRANSPORT_ID, null, self::DECORATION_PRIORITY)
        ;
    }
}
