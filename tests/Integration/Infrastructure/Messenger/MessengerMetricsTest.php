<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Infrastructure\Messenger;

use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\Messenger\TestMessage;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\Messenger\TestMessageOutcome;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\TestKernel;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use Prometheus\RegistryInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatches through the in-memory transport and consumes one message with the real
 * messenger:consume worker, so the worker events reach the bundle's listener.
 */
final class MessengerMetricsTest extends KernelTestCase
{
    private const string DSN_ENV = 'METRICS_STORAGE_DSN';

    #[Override]
    protected function setUp(): void
    {
        if (!TestKernel::hasMessenger() || !class_exists(MonologBundle::class)) {
            self::markTestSkipped('symfony/messenger or symfony/monolog-bundle not installed');
        }

        new Filesystem()->remove(TestKernel::cacheRoot());
        $_SERVER[self::DSN_ENV] = 'inmemory://';
    }

    #[Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        unset($_SERVER[self::DSN_ENV]);
        new Filesystem()->remove(TestKernel::cacheRoot());
    }

    #[Override]
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    /**
     * @return iterable<string, array{TestMessageOutcome, 'handled'|'retried'|'failed'}>
     */
    public static function outcomeProvider(): iterable
    {
        yield 'handled' => [TestMessageOutcome::HANDLED, 'handled'];
        yield 'recoverable failure is retried' => [TestMessageOutcome::RECOVERABLE_FAILURE, 'retried'];
        yield 'unrecoverable failure' => [TestMessageOutcome::UNRECOVERABLE_FAILURE, 'failed'];
    }

    #[DataProvider('outcomeProvider')]
    public function testConsumedMessageIsCountedByOutcome(TestMessageOutcome $outcome, string $status): void
    {
        $kernel = self::bootKernel();
        $container = self::getContainer();

        $bus = $container->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch(new TestMessage($outcome));

        $consume = new CommandTester(new Application($kernel)->find('messenger:consume'));
        $consume->execute(['receivers' => [TestKernel::MESSENGER_TRANSPORT], '--limit' => 1, '--time-limit' => 5]);

        $registry = $container->get(RegistryInterface::class);
        self::assertInstanceOf(RegistryInterface::class, $registry);

        $labels = ['unknown', 'unknown', TestKernel::MESSENGER_TRANSPORT, 'TestMessage'];
        self::assertSame(
            [[$labels, '1']],
            RegistrySamples::samples($registry, MetricLabelEnum::MESSENGER_MESSAGE_SENT),
        );
        self::assertSame(
            [[[...$labels, $status], '1']],
            RegistrySamples::samples($registry, MetricLabelEnum::MESSENGER_MESSAGE_HANDLED),
        );
        self::assertSame(
            [[[...$labels, $status], '1']],
            RegistrySamples::samples($registry, MetricLabelEnum::MESSENGER_MESSAGE_HANDLING_DURATION_HISTOGRAM_SECONDS, '_count'),
        );
    }
}
