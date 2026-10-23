<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Application\Collector;

use Msstc4Symfony\MetricsBundle\Application\Collector\MessengerCollector;
use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Application\Metric\MessengerMessageStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use stdClass;

#[CoversClass(MessengerCollector::class)]
final class MessengerCollectorTest extends RecorderTestCase
{
    public function testIncMessageSentLabelsTransportAndShortMessageClass(): void
    {
        $this->build()->incMessageSent('async', self::class);

        self::assertSame([['increment', BuiltInMetric::MESSENGER_MESSAGE_SENT, null, ['async', 'MessengerCollectorTest']]], $this->recorder->calls);
    }

    public function testIncMessageHandledLabelsTransportMessageAndStatus(): void
    {
        $collector = $this->build();
        $collector->incMessageHandled('async', self::class, MessengerMessageStatus::HANDLED);
        $collector->incMessageHandled('async', self::class, MessengerMessageStatus::RETRIED);
        $collector->incMessageHandled('failed', stdClass::class, MessengerMessageStatus::FAILED);

        self::assertSame(
            [
                ['increment', BuiltInMetric::MESSENGER_MESSAGE_HANDLED, null, ['async', 'MessengerCollectorTest', 'handled']],
                ['increment', BuiltInMetric::MESSENGER_MESSAGE_HANDLED, null, ['async', 'MessengerCollectorTest', 'retried']],
                ['increment', BuiltInMetric::MESSENGER_MESSAGE_HANDLED, null, ['failed', 'stdClass', 'failed']],
            ],
            $this->recorder->calls,
        );
    }

    public function testObserveHandlingDurationUsesTheSameLabels(): void
    {
        $this->build()->observeHandlingDuration('async', self::class, MessengerMessageStatus::FAILED, 0.25);

        self::assertSame(
            [['observe', BuiltInMetric::MESSENGER_MESSAGE_HANDLING_DURATION_HISTOGRAM_SECONDS, 0.25, ['async', 'MessengerCollectorTest', 'failed']]],
            $this->recorder->calls,
        );
    }

    public function testAnonymousMessageClassesCollapseToOneLabelValue(): void
    {
        $collector = $this->build();
        $collector->incMessageSent('async', (new class {})::class);
        $collector->incMessageSent('async', (new class extends stdClass {})::class);

        self::assertSame(
            [
                ['increment', BuiltInMetric::MESSENGER_MESSAGE_SENT, null, ['async', 'class@anonymous']],
                ['increment', BuiltInMetric::MESSENGER_MESSAGE_SENT, null, ['async', 'stdClass@anonymous']],
            ],
            $this->recorder->calls,
        );
    }

    public function testQueueDepthAndItsSampleTimeAreGaugesPerTransport(): void
    {
        $collector = new MessengerCollector($this->recorder);

        $collector->setQueueDepth('async', 12);
        $collector->setQueueDepthSampledAt('async', 1_793_000_000);

        self::assertSame(
            [
                ['set', BuiltInMetric::MESSENGER_QUEUE_DEPTH, 12, ['async']],
                ['set', BuiltInMetric::MESSENGER_QUEUE_DEPTH_SAMPLED_AT, 1_793_000_000, ['async']],
            ],
            $this->recorder->calls,
        );
    }

    private function build(): MessengerCollector
    {
        return new MessengerCollector($this->recorder);
    }
}
