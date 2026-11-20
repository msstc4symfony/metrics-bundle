<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Presentation\EventListener;

use Msstc4Symfony\MetricsBundle\Application\Collector\MessengerCollector;
use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Application\Metric\MessengerMessageStatus;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricRepository;
use Msstc4Symfony\MetricsBundle\Application\Port\MessengerMeasurementObserverInterface;
use Msstc4Symfony\MetricsBundle\Infrastructure\Prometheus\PrometheusMetricRecorder;
use Msstc4Symfony\MetricsBundle\Presentation\EventListener\MessengerEventListener;
use Msstc4Symfony\MetricsBundle\Test\Support\CollectingLogger;
use Msstc4Symfony\MetricsBundle\Test\Support\RecordingMessengerMeasurementObserver;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;
use Msstc4Symfony\MetricsBundle\Test\Support\ThrowingMessengerMeasurementObserver;
use Msstc4Symfony\MetricsBundle\Test\Support\ThrowingMetricRecorder;
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

    private CollectingLogger $logger;

    private RecordingMessengerMeasurementObserver $observer;

    #[Override]
    protected function setUp(): void
    {
        if (!class_exists(WorkerMessageReceivedEvent::class)) {
            self::markTestSkipped('symfony/messenger not installed');
        }

        $this->registry = new CollectorRegistry(new InMemory());
        $this->logger = new CollectingLogger();
        $this->observer = new RecordingMessengerMeasurementObserver();
        $this->listener = $this->listener([$this->observer]);
        $this->envelope = new Envelope(new stdClass());
    }

    public function testHandledMessageIsCountedAndTimed(): void
    {
        $this->listener->onMessageReceived(new WorkerMessageReceivedEvent($this->envelope, 'async'));
        $this->listener->onMessageHandled(new WorkerMessageHandledEvent($this->envelope, 'async'));

        self::assertSame(
            [[['app', 'cmp', 'async', 'stdClass', 'handled'], '1']],
            RegistrySamples::samples($this->registry, BuiltInMetric::MESSENGER_MESSAGE_HANDLED),
        );
        self::assertSame(
            [[['app', 'cmp', 'async', 'stdClass', 'handled'], '1']],
            RegistrySamples::samples($this->registry, BuiltInMetric::MESSENGER_MESSAGE_HANDLING_DURATION_HISTOGRAM_SECONDS, '_count'),
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
            RegistrySamples::samples($this->registry, BuiltInMetric::MESSENGER_MESSAGE_HANDLED),
        );
    }

    public function testFinalFailureIsCountedAsFailed(): void
    {
        $this->listener->onMessageReceived(new WorkerMessageReceivedEvent($this->envelope, 'async'));
        $this->listener->onMessageFailed(new WorkerMessageFailedEvent($this->envelope, 'async', new RuntimeException('boom')));

        self::assertSame(
            [[['app', 'cmp', 'async', 'stdClass', 'failed'], '1']],
            RegistrySamples::samples($this->registry, BuiltInMetric::MESSENGER_MESSAGE_HANDLED),
        );
        self::assertSame(
            [[['app', 'cmp', 'async', 'stdClass', 'failed'], '1']],
            RegistrySamples::samples($this->registry, BuiltInMetric::MESSENGER_MESSAGE_HANDLING_DURATION_HISTOGRAM_SECONDS, '_count'),
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
            RegistrySamples::samples($this->registry, BuiltInMetric::MESSENGER_MESSAGE_HANDLING_DURATION_HISTOGRAM_SECONDS, '_count'),
        );
    }

    public function testStartIsConsumedByTheFirstOutcome(): void
    {
        $this->listener->onMessageReceived(new WorkerMessageReceivedEvent($this->envelope, 'async'));
        $this->listener->onMessageHandled(new WorkerMessageHandledEvent($this->envelope, 'async'));
        $this->listener->onMessageHandled(new WorkerMessageHandledEvent($this->envelope, 'async'));

        self::assertSame(
            [[['app', 'cmp', 'async', 'stdClass', 'handled'], '2']],
            RegistrySamples::samples($this->registry, BuiltInMetric::MESSENGER_MESSAGE_HANDLED),
        );
        self::assertSame(
            [[['app', 'cmp', 'async', 'stdClass', 'handled'], '1']],
            RegistrySamples::samples($this->registry, BuiltInMetric::MESSENGER_MESSAGE_HANDLING_DURATION_HISTOGRAM_SECONDS, '_count'),
        );
    }

    public function testSkippedMessageIsNotTimed(): void
    {
        $received = new WorkerMessageReceivedEvent($this->envelope, 'async');
        $received->shouldHandle(false);

        $this->listener->onMessageReceived($received);
        $this->listener->onMessageHandled(new WorkerMessageHandledEvent($this->envelope, 'async'));

        self::assertCount(1, RegistrySamples::samples($this->registry, BuiltInMetric::MESSENGER_MESSAGE_HANDLED));
        self::assertSame([], RegistrySamples::samples($this->registry, BuiltInMetric::MESSENGER_MESSAGE_HANDLING_DURATION_HISTOGRAM_SECONDS, '_count'));
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
            RegistrySamples::samples($this->registry, BuiltInMetric::MESSENGER_MESSAGE_SENT),
        );
    }

    public function testObserverGetsHandledMeasurementWithDuration(): void
    {
        $this->listener->onMessageReceived(new WorkerMessageReceivedEvent($this->envelope, 'async'));
        $this->listener->onMessageHandled(new WorkerMessageHandledEvent($this->envelope, 'async'));

        self::assertCount(1, $this->observer->measurements);
        $measurement = $this->observer->measurements[0];
        self::assertSame('async', $measurement->transport);
        self::assertSame(stdClass::class, $measurement->messageClass);
        self::assertSame(MessengerMessageStatus::HANDLED, $measurement->status);
        self::assertNotNull($measurement->durationSeconds);
        self::assertGreaterThanOrEqual(0.0, $measurement->durationSeconds);
        self::assertNull($measurement->exception);
    }

    public function testObserverGetsRetriedWithThrowable(): void
    {
        $exception = new RuntimeException('boom');
        $failed = new WorkerMessageFailedEvent($this->envelope, 'async', $exception);
        $failed->setForRetry();

        $this->listener->onMessageReceived(new WorkerMessageReceivedEvent($this->envelope, 'async'));
        $this->listener->onMessageFailed($failed);

        self::assertCount(1, $this->observer->measurements);
        self::assertSame(MessengerMessageStatus::RETRIED, $this->observer->measurements[0]->status);
        self::assertSame($exception, $this->observer->measurements[0]->exception);
    }

    public function testObserverGetsFailedWithThrowable(): void
    {
        $exception = new RuntimeException('boom');

        $this->listener->onMessageReceived(new WorkerMessageReceivedEvent($this->envelope, 'async'));
        $this->listener->onMessageFailed(new WorkerMessageFailedEvent($this->envelope, 'async', $exception));

        self::assertCount(1, $this->observer->measurements);
        self::assertSame(MessengerMessageStatus::FAILED, $this->observer->measurements[0]->status);
        self::assertSame($exception, $this->observer->measurements[0]->exception);
    }

    public function testObserverGetsNullDurationWithoutStart(): void
    {
        $this->listener->onMessageHandled(new WorkerMessageHandledEvent($this->envelope, 'async'));

        self::assertCount(1, $this->observer->measurements);
        self::assertNull($this->observer->measurements[0]->durationSeconds);
    }

    public function testObserverGetsTheSameDurationThatWasRecorded(): void
    {
        $this->listener->onMessageReceived(new WorkerMessageReceivedEvent($this->envelope, 'async'));
        $this->listener->onMessageHandled(new WorkerMessageHandledEvent($this->envelope, 'async'));

        $sum = RegistrySamples::samples($this->registry, BuiltInMetric::MESSENGER_MESSAGE_HANDLING_DURATION_HISTOGRAM_SECONDS, '_sum');
        self::assertCount(1, $sum);
        self::assertEqualsWithDelta((float) $sum[0][1], $this->observer->measurements[0]->durationSeconds, 1e-12);
    }

    public function testThrowingObserverIsLoggedAndOthersStillRun(): void
    {
        $listener = $this->listener([new ThrowingMessengerMeasurementObserver(), $this->observer]);

        $listener->onMessageReceived(new WorkerMessageReceivedEvent($this->envelope, 'async'));
        $listener->onMessageHandled(new WorkerMessageHandledEvent($this->envelope, 'async'));

        self::assertCount(1, $this->observer->measurements);
        self::assertCount(1, $this->logger->records);
        self::assertStringStartsWith('error: Cannot notify Messenger measurement observer', $this->logger->records[0]);
        self::assertInstanceOf(RuntimeException::class, $this->logger->contexts[0]['exception'] ?? null);
        self::assertSame(ThrowingMessengerMeasurementObserver::class, $this->logger->contexts[0]['class'] ?? null);
    }

    public function testThrowingRecorderStillNotifiesObserversAndDropsTheStart(): void
    {
        $message = new stdClass();
        $envelope = new Envelope($message);
        $listener = new MessengerEventListener(new MessengerCollector(new ThrowingMetricRecorder()), [$this->observer], $this->logger);

        $listener->onMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));
        $listener->onMessageFailed(new WorkerMessageFailedEvent($envelope, 'async', new RuntimeException('boom')));

        self::assertCount(1, $this->observer->measurements);
        self::assertSame(MessengerMessageStatus::FAILED, $this->observer->measurements[0]->status);
        self::assertNotNull($this->observer->measurements[0]->durationSeconds);
        self::assertSame(['error: Cannot record Messenger metrics'], $this->logger->records);
        self::assertInstanceOf(RuntimeException::class, $this->logger->contexts[0]['exception'] ?? null);

        $listener->onMessageHandled(new WorkerMessageHandledEvent($envelope, 'async'));
        self::assertCount(2, $this->observer->measurements);
        self::assertNull($this->observer->measurements[1]->durationSeconds);
    }

    public function testThrowingRecorderDoesNotBreakSending(): void
    {
        $listener = new MessengerEventListener(new MessengerCollector(new ThrowingMetricRecorder()), [], $this->logger);

        $listener->onSendMessageToTransports(new SendMessageToTransportsEvent($this->envelope, ['async' => new InMemoryTransport()]));

        self::assertSame(['error: Cannot record Messenger metrics'], $this->logger->records);
    }

    /**
     * @param list<MessengerMeasurementObserverInterface> $observers
     */
    private function listener(array $observers): MessengerEventListener
    {
        return new MessengerEventListener(
            new MessengerCollector(new PrometheusMetricRecorder($this->registry, new MetricRepository([]), 'app', 'cmp')),
            $observers,
            $this->logger,
        );
    }
}
