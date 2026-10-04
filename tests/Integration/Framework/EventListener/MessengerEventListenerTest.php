<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Framework\EventListener;

use Msstc4Symfony\MetricsBundle\Framework\EventListener\MessengerEventListener;
use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\MessengerCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepository;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;
use Override;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;
use RuntimeException;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use WeakReference;

final class MessengerEventListenerTest extends TestCase
{
    private CollectorRegistry $registry;

    private MessengerEventListener $listener;

    private Envelope $envelope;

    #[Override]
    protected function setUp(): void
    {
        if (!class_exists(WorkerMessageReceivedEvent::class)) {
            self::markTestSkipped('symfony/messenger not installed');
        }

        $this->registry = new CollectorRegistry(new InMemory());
        $this->listener = new MessengerEventListener(
            new MessengerCollector($this->registry, new MetricRepository([]), 'app', 'cmp'),
        );
        $this->envelope = new Envelope(new stdClass());
    }

    public function testHandledMessageIsCountedAndTimed(): void
    {
        $this->listener->onMessageReceived(new WorkerMessageReceivedEvent($this->envelope, 'async'));
        $this->listener->onMessageHandled(new WorkerMessageHandledEvent($this->envelope, 'async'));

        self::assertSame(
            [[['app', 'cmp', 'async', 'stdClass', 'handled'], '1']],
            RegistrySamples::samples($this->registry, MetricLabelEnum::MESSENGER_MESSAGE_HANDLED),
        );
        self::assertSame(
            [[['app', 'cmp', 'async', 'stdClass', 'handled'], '1']],
            RegistrySamples::samples($this->registry, MetricLabelEnum::MESSENGER_MESSAGE_HANDLING_DURATION_HISTOGRAM_SECONDS, '_count'),
        );
    }

    public function testFailureThatWillBeRetriedIsCountedAsRetried(): void
    {
        $failed = new WorkerMessageFailedEvent($this->envelope, 'async', new RuntimeException('boom'));
        $failed->setForRetry();

        $this->listener->onMessageReceived(new WorkerMessageReceivedEvent($this->envelope, 'async'));
        $this->listener->onMessageFailed($failed);

        self::assertSame(
            [[['app', 'cmp', 'async', 'stdClass', 'retried'], '1']],
            RegistrySamples::samples($this->registry, MetricLabelEnum::MESSENGER_MESSAGE_HANDLED),
        );
    }

    public function testFinalFailureIsCountedAsFailed(): void
    {
        $this->listener->onMessageReceived(new WorkerMessageReceivedEvent($this->envelope, 'async'));
        $this->listener->onMessageFailed(new WorkerMessageFailedEvent($this->envelope, 'async', new RuntimeException('boom')));

        self::assertSame(
            [[['app', 'cmp', 'async', 'stdClass', 'failed'], '1']],
            RegistrySamples::samples($this->registry, MetricLabelEnum::MESSENGER_MESSAGE_HANDLED),
        );
        self::assertSame(
            [[['app', 'cmp', 'async', 'stdClass', 'failed'], '1']],
            RegistrySamples::samples($this->registry, MetricLabelEnum::MESSENGER_MESSAGE_HANDLING_DURATION_HISTOGRAM_SECONDS, '_count'),
        );
    }

    public function testBatchedMessagesAcknowledgedLaterKeepTheirOwnStart(): void
    {
        $second = new Envelope(new stdClass());

        $this->listener->onMessageReceived(new WorkerMessageReceivedEvent($this->envelope, 'async'));
        $this->listener->onMessageReceived(new WorkerMessageReceivedEvent($second, 'async'));
        $this->listener->onMessageHandled(new WorkerMessageHandledEvent($this->envelope, 'async'));
        $this->listener->onMessageHandled(new WorkerMessageHandledEvent($second, 'async'));

        self::assertSame(
            [[['app', 'cmp', 'async', 'stdClass', 'handled'], '2']],
            RegistrySamples::samples($this->registry, MetricLabelEnum::MESSENGER_MESSAGE_HANDLING_DURATION_HISTOGRAM_SECONDS, '_count'),
        );
    }

    public function testStartIsConsumedByTheFirstOutcome(): void
    {
        $this->listener->onMessageReceived(new WorkerMessageReceivedEvent($this->envelope, 'async'));
        $this->listener->onMessageHandled(new WorkerMessageHandledEvent($this->envelope, 'async'));
        $this->listener->onMessageHandled(new WorkerMessageHandledEvent($this->envelope, 'async'));

        self::assertSame(
            [[['app', 'cmp', 'async', 'stdClass', 'handled'], '2']],
            RegistrySamples::samples($this->registry, MetricLabelEnum::MESSENGER_MESSAGE_HANDLED),
        );
        self::assertSame(
            [[['app', 'cmp', 'async', 'stdClass', 'handled'], '1']],
            RegistrySamples::samples($this->registry, MetricLabelEnum::MESSENGER_MESSAGE_HANDLING_DURATION_HISTOGRAM_SECONDS, '_count'),
        );
    }

    public function testSkippedMessageIsNotTimed(): void
    {
        $received = new WorkerMessageReceivedEvent($this->envelope, 'async');
        $received->shouldHandle(false);

        $this->listener->onMessageReceived($received);
        $this->listener->onMessageHandled(new WorkerMessageHandledEvent($this->envelope, 'async'));

        self::assertCount(1, RegistrySamples::samples($this->registry, MetricLabelEnum::MESSENGER_MESSAGE_HANDLED));
        self::assertSame([], RegistrySamples::samples($this->registry, MetricLabelEnum::MESSENGER_MESSAGE_HANDLING_DURATION_HISTOGRAM_SECONDS, '_count'));
    }

    public function testMessageWithoutOutcomeIsNotRetainedByTheLongRunningListener(): void
    {
        $message = new stdClass();
        $reference = WeakReference::create($message);

        $this->listener->onMessageReceived(new WorkerMessageReceivedEvent(new Envelope($message), 'async'));
        unset($message);

        self::assertNull($reference->get());
    }

    public function testEachTransportOfASentMessageIsCounted(): void
    {
        $transport = new InMemoryTransport();

        $this->listener->onSendMessageToTransports(
            new SendMessageToTransportsEvent($this->envelope, ['async' => $transport, 'audit' => $transport]),
        );

        self::assertSame(
            [
                [['app', 'cmp', 'async', 'stdClass'], '1'],
                [['app', 'cmp', 'audit', 'stdClass'], '1'],
            ],
            RegistrySamples::samples($this->registry, MetricLabelEnum::MESSENGER_MESSAGE_SENT),
        );
    }
}
