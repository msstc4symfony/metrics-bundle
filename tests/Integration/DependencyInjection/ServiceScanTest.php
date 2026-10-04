<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\DependencyInjection;

use MongoDB\Driver\Monitoring\CommandSubscriber;
use Msstc4Symfony\MetricsBundle\Framework\EventListener\MessengerEventListener;
use Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\ODM\Metrics\TimingSubscriber;
use Msstc4Symfony\MetricsBundle\Infrastructure\HttpClient\CompletionStreamFilter;
use Msstc4Symfony\MetricsBundle\Infrastructure\HttpClient\HttpClientDecorator;
use Msstc4Symfony\MetricsBundle\Infrastructure\HttpClient\MonitoredResponse;
use Msstc4Symfony\MetricsBundle\Infrastructure\HttpClient\PathSanitizer;
use Msstc4Symfony\MetricsBundle\Infrastructure\HttpClient\URLAssembler\AssemblerInterface;
use Msstc4Symfony\MetricsBundle\MetricsBundle;
use Msstc4Symfony\MetricsBundle\Test\Integration\DependencyInjection\Fixture\StaticUrlAssembler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\RegisterAutoconfigureAttributesPass;
use Symfony\Component\DependencyInjection\Compiler\ResolveInstanceofConditionalsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

/**
 * Classes that need an optional library must stay out of the resource scan: symfony/dependency-injection
 * 7.4.0 loads every scanned class and fatals when its interface is missing ("Interface ... not found").
 */
final class ServiceScanTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string}>
     */
    public static function httpClientClassProvider(): iterable
    {
        yield 'MonitoredResponse' => [MonitoredResponse::class];
        yield 'HttpClientDecorator' => [HttpClientDecorator::class];
        yield 'CompletionStreamFilter' => [CompletionStreamFilter::class];
        yield 'PathSanitizer' => [PathSanitizer::class];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('httpClientClassProvider')]
    public function testHttpClientClassesAreNotScanned(string $class): void
    {
        self::assertFalse($this->isRegistered($this->loadedContainer(), $class));
    }

    public function testMongoDbSubscriberIsRegisteredOnlyWithTheDriver(): void
    {
        self::assertSame(interface_exists(CommandSubscriber::class), $this->isRegistered($this->loadedContainer(), TimingSubscriber::class));
    }

    public function testMessengerListenerIsRegisteredOnlyWithMessenger(): void
    {
        self::assertSame(class_exists(WorkerMessageReceivedEvent::class), $this->isRegistered($this->loadedContainer(), MessengerEventListener::class));
    }

    public function testAnApplicationAssemblerIsTaggedThroughTheInterfaceAttribute(): void
    {
        $container = $this->loadedContainer();
        $container->register(StaticUrlAssembler::class, StaticUrlAssembler::class)->setAutoconfigured(true);

        new RegisterAutoconfigureAttributesPass()->process($container);
        new ResolveInstanceofConditionalsPass()->process($container);

        self::assertSame([[]], $container->getDefinition(StaticUrlAssembler::class)->getTag(AssemblerInterface::TAG));
    }

    public function testEveryRegisteredServiceLoadsWithTheInstalledLibraries(): void
    {
        foreach ($this->loadedContainer()->getDefinitions() as $id => $definition) {
            self::assertSame([], $definition->getErrors(), \sprintf('Service "%s" failed to load', $id));
        }
    }

    /**
     * Excluded paths still get a definition, tagged "container.excluded" and never reflected.
     */
    private function isRegistered(ContainerBuilder $container, string $id): bool
    {
        return $container->hasDefinition($id) && !$container->getDefinition($id)->hasTag('container.excluded');
    }

    private function loadedContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());

        $extension = new MetricsBundle()->getContainerExtension();
        self::assertInstanceOf(ExtensionInterface::class, $extension);
        $extension->load([], $container);

        return $container;
    }
}
