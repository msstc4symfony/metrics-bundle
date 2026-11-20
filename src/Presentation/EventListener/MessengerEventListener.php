<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Presentation\EventListener;

use Monolog\Attribute\WithMonologChannel;
use Msstc4Symfony\MetricsBundle\Application\Collector\MessengerCollector;
use Msstc4Symfony\MetricsBundle\Application\LogChannel;
use Msstc4Symfony\MetricsBundle\Application\Metric\MessengerMessageStatus;
use Msstc4Symfony\MetricsBundle\Application\Port\MessengerMeasurement;
use Msstc4Symfony\MetricsBundle\Application\Port\MessengerMeasurementObserverInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\AbstractWorkerMessageEvent;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Throwable;
use WeakMap;

/**
 * Registered only when symfony/messenger is installed (see src/Resources/config/services.php).
 *
 * Start times are keyed by the message object, which the worker keeps across the received
 * and outcome events (envelopes are re-created). Batch handlers acknowledge messages after
 * later ones were received and after the worker reset services, so a single slot cleared on
 * kernel.reset would lose them; the WeakMap entry goes away with the message instead.
 *
 * @internal
 */
#[WithMonologChannel(LogChannel::NAME)]
#[AsEventListener(event: SendMessageToTransportsEvent::class, method: 'onSendMessageToTransports')]
// Lowest priority: start the clock right before the bus handles the message.
#[AsEventListener(event: WorkerMessageReceivedEvent::class, method: 'onMessageReceived', priority: -1024)]
#[AsEventListener(event: WorkerMessageHandledEvent::class, method: 'onMessageHandled')]
// Below SendFailedMessageForRetryListener (100), which decides willRetry().
#[AsEventListener(event: WorkerMessageFailedEvent::class, method: 'onMessageFailed')]
final class MessengerEventListener
{
    /** @var WeakMap<object, float|int> hrtime() nanoseconds */
    private WeakMap $startedAt;

    /**
     * @param iterable<int, MessengerMeasurementObserverInterface> $observers
     */
    public function __construct(
        private readonly MessengerCollector $collector,
        #[AutowireIterator(MessengerMeasurementObserverInterface::TAG)]
        private readonly iterable $observers,
        private readonly LoggerInterface $logger,
    ) {
        $this->startedAt = new WeakMap();
    }

    public function onSendMessageToTransports(SendMessageToTransportsEvent $event): void
    {
        $messageClass = $event->getEnvelope()->getMessage()::class;
        try {
            foreach (array_keys($event->getSenders()) as $transport) {
                $this->collector->incMessageSent((string) $transport, $messageClass);
            }
        } catch (Throwable $e) {
            $this->logger->error('Cannot record Messenger metrics', ['exception' => $e]);
        }
    }

    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if ($event->shouldHandle()) {
            $this->startedAt[$message] = hrtime(true);
        } else {
            unset($this->startedAt[$message]);
        }
    }

    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $this->record($event, MessengerMessageStatus::HANDLED);
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $this->record($event, $event->willRetry() ? MessengerMessageStatus::RETRIED : MessengerMessageStatus::FAILED);
    }

    private function record(AbstractWorkerMessageEvent $event, MessengerMessageStatus $status): void
    {
        $transport = $event->getReceiverName();
        $message = $event->getEnvelope()->getMessage();
        $startedAt = $this->startedAt[$message] ?? null;
        unset($this->startedAt[$message]);

        $duration = $startedAt === null ? null : (hrtime(true) - $startedAt) / 1e9;

        try {
            $this->collector->incMessageHandled($transport, $message::class, $status);

            if ($duration !== null) {
                $this->collector->observeHandlingDuration($transport, $message::class, $status, $duration);
            }
        } catch (Throwable $e) {
            $this->logger->error('Cannot record Messenger metrics', ['exception' => $e]);
        }

        $this->notify($transport, $message::class, $status, $duration, $event instanceof WorkerMessageFailedEvent ? $event->getThrowable() : null);
    }

    /**
     * @param class-string $messageClass
     */
    private function notify(string $transport, string $messageClass, MessengerMessageStatus $status, ?float $duration, ?Throwable $exception): void
    {
        if ($transport === '') {
            $this->logger->error('Cannot build Messenger measurement: the receiver name is empty');

            return;
        }

        try {
            $measurement = new MessengerMeasurement($transport, $messageClass, $status, $duration, $exception);
        } catch (Throwable $e) {
            $this->logger->error('Cannot build Messenger measurement', ['exception' => $e]);

            return;
        }

        foreach ($this->observers as $observer) {
            try {
                $observer->onMessageMeasured($measurement);
            } catch (Throwable $e) {
                $this->logger->error('Cannot notify Messenger measurement observer "{class}"', ['class' => $observer::class, 'exception' => $e]);
            }
        }
    }
}
