<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Infrastructure\Collector;

use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\MessengerCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MessengerMessageStatusEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepository;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;
use stdClass;

final class MessengerCollectorTest extends CollectorTestCase
{
    public function testIncMessageSentLabelsTransportAndShortMessageClass(): void
    {
        $this->build()->incMessageSent('async', self::class);

        self::assertSame(
            [[['app', 'cmp', 'async', 'MessengerCollectorTest'], '1']],
            RegistrySamples::samples($this->registry, MetricLabelEnum::MESSENGER_MESSAGE_SENT),
        );
    }

    public function testIncMessageHandledLabelsTransportMessageAndStatus(): void
    {
        $collector = $this->build();
        $collector->incMessageHandled('async', self::class, MessengerMessageStatusEnum::HANDLED);
        $collector->incMessageHandled('async', self::class, MessengerMessageStatusEnum::RETRIED);
        $collector->incMessageHandled('failed', stdClass::class, MessengerMessageStatusEnum::FAILED);

        self::assertSame(
            [
                [['app', 'cmp', 'async', 'MessengerCollectorTest', 'handled'], '1'],
                [['app', 'cmp', 'async', 'MessengerCollectorTest', 'retried'], '1'],
                [['app', 'cmp', 'failed', 'stdClass', 'failed'], '1'],
            ],
            RegistrySamples::samples($this->registry, MetricLabelEnum::MESSENGER_MESSAGE_HANDLED),
        );
    }

    public function testObserveHandlingDurationUsesTheSameLabels(): void
    {
        $this->build()->observeHandlingDuration('async', self::class, MessengerMessageStatusEnum::FAILED, 0.25);

        self::assertSame(
            [[['app', 'cmp', 'async', 'MessengerCollectorTest', 'failed'], '1']],
            RegistrySamples::samples($this->registry, MetricLabelEnum::MESSENGER_MESSAGE_HANDLING_DURATION_HISTOGRAM_SECONDS, '_count'),
        );
    }

    public function testAnonymousMessageClassesCollapseToOneLabelValue(): void
    {
        $collector = $this->build();
        $collector->incMessageSent('async', new class {}::class);
        $collector->incMessageSent('async', new class extends stdClass {}::class);

        self::assertSame(
            [
                [['app', 'cmp', 'async', 'class@anonymous'], '1'],
                [['app', 'cmp', 'async', 'stdClass@anonymous'], '1'],
            ],
            RegistrySamples::samples($this->registry, MetricLabelEnum::MESSENGER_MESSAGE_SENT),
        );
    }

    private function build(): MessengerCollector
    {
        return new MessengerCollector($this->registry, new MetricRepository([]), 'app', 'cmp');
    }
}
