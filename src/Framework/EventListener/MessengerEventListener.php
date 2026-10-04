<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Framework\EventListener;

use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\MessengerCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MessengerMessageStatusEnum;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\AbstractWorkerMessageEvent;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use WeakMap;

/**
 * Registered only when symfony/messenger is installed (see MetricsExtension).
 *
 * Start times are keyed by the message object, which the worker keeps across the received
 * and outcome events (envelopes are re-created). Batch handlers acknowledge messages after
 * later ones were received and after the worker reset services, so a single slot cleared on
 * kernel.reset would lose them; the WeakMap entry goes away with the message instead.
 */
#[AsEventListener(event: SendMessageToTransportsEvent::class, method: 'onSendMessageToTransports')]
// Lowest priority: start the clock right before the bus handles the message.
#[AsEventListener(event: WorkerMessageReceivedEvent::class, method: 'onMessageReceived', priority: -1024)]
#[AsEventListener(event: WorkerMessageHandledEvent::class, method: 'onMessageHandled')]
// Below SendFailedMessageForRetryListener (100), which decides willRetry().
#[AsEventListener(event: WorkerMessageFailedEvent::class, method: 'onMessageFailed')]
final class MessengerEventListener
{
    /** @var WeakMap<object, float> */
    private WeakMap $startedAt;

    public function __construct(
        private readonly MessengerCollector $collector,
    ) {
        $this->startedAt = new WeakMap();
    }

    public function onSendMessageToTransports(SendMessageToTransportsEvent $event): void
    {
        $messageClass = $event->getEnvelope()->getMessage()::class;
        foreach (array_keys($event->getSenders()) as $transport) {
            $this->collector->incMessageSent((string) $transport, $messageClass);
        }
    }

    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if ($event->shouldHandle()) {
            $this->startedAt[$message] = microtime(true);
        } else {
            unset($this->startedAt[$message]);
        }
    }

    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $this->record($event, MessengerMessageStatusEnum::HANDLED);
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $this->record($event, $event->willRetry() ? MessengerMessageStatusEnum::RETRIED : MessengerMessageStatusEnum::FAILED);
    }

    private function record(AbstractWorkerMessageEvent $event, MessengerMessageStatusEnum $status): void
    {
        $transport = $event->getReceiverName();
        $message = $event->getEnvelope()->getMessage();
        $startedAt = $this->startedAt[$message] ?? null;
        unset($this->startedAt[$message]);

        $this->collector->incMessageHandled($transport, $message::class, $status);

        if ($startedAt !== null) {
            $this->collector->observeHandlingDuration($transport, $message::class, $status, microtime(true) - $startedAt);
        }
    }
}
