<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\DependencyInjection;

use Monolog\Handler\TestHandler;
use Msstc4Symfony\MetricsBundle\Application\Metric\MessengerMessageStatus;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\Messenger\TestMessage;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\Messenger\TestMessageOutcome;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\TestKernel;
use Msstc4Symfony\MetricsBundle\Test\Support\RecordingMessengerMeasurementObserver;
use Msstc4Symfony\MetricsBundle\Test\Support\ThrowingMessengerMeasurementObserver;
use Override;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

final class MessengerMeasurementObserverWiringTest extends KernelTestCase
{
    private const DSN_ENV = 'METRICS_STORAGE_DSN';

    #[Override]
    protected function setUp(): void
    {
        if (!TestKernel::hasMessenger() || !class_exists(MonologBundle::class)) {
            self::markTestSkipped('symfony/messenger or symfony/monolog-bundle not installed');
        }

        (new Filesystem())->remove(TestKernel::cacheRoot());
        $_SERVER[self::DSN_ENV] = 'inmemory://';
    }

    #[Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        unset($_SERVER[self::DSN_ENV]);
        (new Filesystem())->remove(TestKernel::cacheRoot());
    }

    public function testAutoconfiguredObserverSeesConsumedMessageAndFailureIsLoggedOnMetricsChannel(): void
    {
        self::bootKernel(['environment' => TestKernel::ENV_MESSENGER_OBSERVER]);
        $container = self::getContainer();

        // Dispatched directly: the worker resets services (and the test handler) after every message.
        $dispatcher = $container->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $envelope = new Envelope(new TestMessage(TestMessageOutcome::HANDLED));
        $dispatcher->dispatch(new WorkerMessageReceivedEvent($envelope, TestKernel::MESSENGER_TRANSPORT));
        $dispatcher->dispatch(new WorkerMessageHandledEvent($envelope, TestKernel::MESSENGER_TRANSPORT));

        $observer = $container->get(RecordingMessengerMeasurementObserver::class);
        self::assertInstanceOf(RecordingMessengerMeasurementObserver::class, $observer);
        self::assertCount(1, $observer->measurements);
        self::assertSame(TestKernel::MESSENGER_TRANSPORT, $observer->measurements[0]->transport);
        self::assertSame(TestMessage::class, $observer->measurements[0]->messageClass);
        self::assertSame(MessengerMessageStatus::HANDLED, $observer->measurements[0]->status);
        self::assertNotNull($observer->measurements[0]->durationSeconds);

        $metrics = $container->get('monolog.handler.metrics');
        self::assertInstanceOf(TestHandler::class, $metrics);
        $records = $metrics->getRecords();
        self::assertCount(1, $records);
        self::assertStringStartsWith('Cannot notify Messenger measurement observer', $records[0]->message);
        self::assertSame(ThrowingMessengerMeasurementObserver::class, $records[0]->context['class'] ?? null);
    }

    #[Override]
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }
}
