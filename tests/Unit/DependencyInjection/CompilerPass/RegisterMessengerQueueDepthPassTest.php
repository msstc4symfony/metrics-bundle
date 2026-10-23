<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\DependencyInjection\CompilerPass;

use InvalidArgumentException;
use LogicException;
use Msstc4Symfony\MetricsBundle\DependencyInjection\CompilerPass\RegisterMessengerQueueDepthPass;
use Msstc4Symfony\MetricsBundle\Infrastructure\Messenger\MessengerQueueDepthReader;
use Msstc4Symfony\MetricsBundle\Presentation\EventListener\MessengerQueueDepthListener;
use Msstc4Symfony\MetricsBundle\Test\Support\Messenger\CountableReceiver;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;

#[RequiresMethod(MessageCountAwareInterface::class, 'getMessageCount')]
final class RegisterMessengerQueueDepthPassTest extends TestCase
{
    public function testRegistersNothingWhenDisabled(): void
    {
        $container = $this->container(false, []);

        (new RegisterMessengerQueueDepthPass())->process($container);

        self::assertFalse($container->hasDefinition(MessengerQueueDepthListener::class));
    }

    public function testSamplesEveryReceiverThatMayCountByItsTransportName(): void
    {
        $container = $this->container(true, []);

        (new RegisterMessengerQueueDepthPass())->process($container);

        $listener = $container->getDefinition(MessengerQueueDepthListener::class);
        self::assertTrue($listener->isAutowired());
        self::assertSame(['async', 'failed', 'app.receiver'], $listener->getArgument('$transports'));
        self::assertFalse($listener->getArgument('$listed'));
        self::assertSame('%' . RegisterMessengerQueueDepthPass::PERIOD_PARAMETER . '%', $listener->getArgument('$period'));
        self::assertEquals(new Reference(MessengerQueueDepthReader::class), $listener->getArgument('$reader'));
        self::assertEquals(new Reference('monolog.logger.metrics_bundle'), $listener->getArgument('$logger'));
        self::assertSame(
            [
                ['event' => 'kernel.terminate', 'method' => 'sample', 'priority' => -4096],
                ['event' => WorkerRunningEvent::class, 'method' => 'sample'],
            ],
            $listener->getTag('kernel.event_listener'),
        );
        self::assertSame(
            ['app.receiver' => 'app.receiver', 'async' => 'messenger.transport.async', 'failed' => 'messenger.transport.failed'],
            $this->locatorTargets($container),
        );
    }

    public function testSamplesTheListedTransportsOnlyInTheirOrder(): void
    {
        $container = $this->container(true, ['failed', 'async']);

        (new RegisterMessengerQueueDepthPass())->process($container);

        $listener = $container->getDefinition(MessengerQueueDepthListener::class);
        self::assertSame(['failed', 'async'], $listener->getArgument('$transports'));
        self::assertTrue($listener->getArgument('$listed'));
        self::assertSame(['async' => 'messenger.transport.async', 'failed' => 'messenger.transport.failed'], $this->locatorTargets($container));
    }

    public function testSkipsNonNamesInTheTransportList(): void
    {
        $container = $this->container(true, []);
        $container->setParameter(RegisterMessengerQueueDepthPass::TRANSPORTS_PARAMETER, ['failed', 42, 'async']);

        (new RegisterMessengerQueueDepthPass())->process($container);

        self::assertSame(['failed', 'async'], $container->getDefinition(MessengerQueueDepthListener::class)->getArgument('$transports'));
    }

    public function testAReceiverOfAClassThatCannotCountIsLeftOut(): void
    {
        $container = $this->container(true, []);
        $container->register('app.memory', InMemoryTransport::class)->addTag('messenger.receiver', ['alias' => 'memory']);

        (new RegisterMessengerQueueDepthPass())->process($container);

        $transports = $container->getDefinition(MessengerQueueDepthListener::class)->getArgument('$transports');
        self::assertIsArray($transports);
        self::assertNotContains('memory', $transports);
    }

    public function testListingAReceiverOfAClassThatCannotCountIsAnError(): void
    {
        $container = $this->container(true, ['memory']);
        $container->register('app.memory', InMemoryTransport::class)->addTag('messenger.receiver', ['alias' => 'memory']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Messenger transport "memory" listed in msstc4symfony_metrics.messenger.queue_depth.transports cannot count its messages: ' . InMemoryTransport::class . ' does not implement ' . MessageCountAwareInterface::class . '.');

        (new RegisterMessengerQueueDepthPass())->process($container);
    }

    public function testAReceiverWhoseClassCannotBeLoadedIsLeftToTheRuntimeCheck(): void
    {
        // Generated at run time: a source file extending a missing class would fail static analysis.
        $class = 'Msstc4Symfony\\MetricsBundle\\Test\\Generated\\OrphanReceiver';
        $file = sys_get_temp_dir() . '/msstc4symfony-metrics-orphan-receiver-' . getmypid() . '.php';
        file_put_contents($file, "<?php\nnamespace Msstc4Symfony\\MetricsBundle\\Test\\Generated;\nfinal class OrphanReceiver extends \\Not\\Installed\\BaseReceiver {}\n");
        $autoload = static function (string $name) use ($class, $file): void {
            if ($name === $class) {
                require $file;
            }
        };
        spl_autoload_register($autoload);

        try {
            $container = $this->container(true, ['orphan']);
            $container->register('app.orphan', $class)->addTag('messenger.receiver', ['alias' => 'orphan']);

            (new RegisterMessengerQueueDepthPass())->process($container);

            self::assertSame(['orphan'], $container->getDefinition(MessengerQueueDepthListener::class)->getArgument('$transports'));
        } finally {
            spl_autoload_unregister($autoload);
            unlink($file);
        }
    }

    public function testWithoutACountableTransportNothingIsRegisteredAndTheLogSaysSo(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(RegisterMessengerQueueDepthPass::ENABLED_PARAMETER, true);
        $container->setParameter(RegisterMessengerQueueDepthPass::TRANSPORTS_PARAMETER, []);
        $container->register('messenger.receiver_locator', ServiceLocator::class)->addArgument([]);
        $container->register('app.memory', InMemoryTransport::class)->addTag('messenger.receiver', ['alias' => 'memory']);

        (new RegisterMessengerQueueDepthPass())->process($container);

        self::assertFalse($container->hasDefinition(MessengerQueueDepthListener::class));
        self::assertFalse($container->hasDefinition(MessengerQueueDepthReader::class));
        self::assertSame(
            [RegisterMessengerQueueDepthPass::class . ': No Messenger transport can count its messages: msstc4symfony_metrics.messenger.queue_depth samples nothing.'],
            $container->getCompiler()->getLog(),
        );
    }

    public function testAnUnknownTransportIsAnError(): void
    {
        $container = $this->container(true, ['async', 'missing']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Messenger transport "missing" listed in msstc4symfony_metrics.messenger.queue_depth.transports does not exist; transports: "async", "failed", "app.receiver".');

        (new RegisterMessengerQueueDepthPass())->process($container);
    }

    public function testNeedsMessenger(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(RegisterMessengerQueueDepthPass::ENABLED_PARAMETER, true);
        $container->setParameter(RegisterMessengerQueueDepthPass::TRANSPORTS_PARAMETER, []);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('msstc4symfony_metrics.messenger.queue_depth needs Symfony Messenger (framework.messenger).');

        (new RegisterMessengerQueueDepthPass())->process($container);
    }

    public function testWithoutTheBundleParametersNothingIsRegistered(): void
    {
        $container = new ContainerBuilder();

        (new RegisterMessengerQueueDepthPass())->process($container);

        self::assertFalse($container->hasDefinition(MessengerQueueDepthListener::class));
    }

    /**
     * @param list<string> $transports
     */
    private function container(bool $enabled, array $transports): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(RegisterMessengerQueueDepthPass::ENABLED_PARAMETER, $enabled);
        $container->setParameter(RegisterMessengerQueueDepthPass::TRANSPORTS_PARAMETER, $transports);
        $container->register('messenger.receiver_locator', ServiceLocator::class)->addArgument([]);
        $container->register('messenger.transport.async')->addTag('messenger.receiver', ['alias' => 'async']);
        $container->register('messenger.transport.failed')->addTag('messenger.receiver', ['alias' => 'failed', 'is_failure_transport' => true]);
        $container->register('app.receiver', CountableReceiver::class)->addTag('messenger.receiver');

        return $container;
    }

    /**
     * @return array<string, string> transport name => receiver service id
     */
    private function locatorTargets(ContainerBuilder $container): array
    {
        $locator = $container->getDefinition(MessengerQueueDepthReader::class)->getArgument('$receivers');
        self::assertInstanceOf(Reference::class, $locator);
        $definition = $container->getDefinition((string) $locator);
        self::assertSame(ServiceLocator::class, $definition->getClass());

        $targets = [];
        $services = $definition->getArgument(0);
        self::assertIsArray($services);
        foreach ($services as $name => $service) {
            self::assertInstanceOf(ServiceClosureArgument::class, $service);
            $reference = $service->getValues()[0] ?? null;
            self::assertInstanceOf(Reference::class, $reference);
            $targets[(string) $name] = (string) $reference;
        }

        ksort($targets);

        return $targets;
    }
}
