<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Enum;

use Msstc4Symfony\MetricsBundle\Infrastructure\Entity\Label;
use Override;

/**
 * A separate enum rather than new MetricLabelEnum cases: adding cases breaks exhaustive
 * match() over MetricLabelEnum in applications (Roave BC check).
 */
enum MessengerMetricLabelEnum: string implements MetricLabelEnumInterface
{
    case MESSAGE_SENT = 'messenger_message_sent';
    case MESSAGE_HANDLED = 'messenger_message_handled';
    case MESSAGE_HANDLING_DURATION_HISTOGRAM_SECONDS = 'messenger_message_handling_duration_histogram_seconds';

    #[Override]
    public function getType(): MetricTypeEnum
    {
        return match ($this) {
            self::MESSAGE_SENT, self::MESSAGE_HANDLED => MetricTypeEnum::COUNTER,
            self::MESSAGE_HANDLING_DURATION_HISTOGRAM_SECONDS => MetricTypeEnum::HISTOGRAM,
        };
    }

    #[Override]
    public function getDescription(): string
    {
        return match ($this) {
            self::MESSAGE_SENT => 'Messenger messages sent to a transport count',
            self::MESSAGE_HANDLED => 'Messenger messages consumed by workers count, by outcome',
            self::MESSAGE_HANDLING_DURATION_HISTOGRAM_SECONDS => 'Messenger message handling duration in workers in seconds',
        };
    }

    #[Override]
    public function getLabels(): array
    {
        $transport = new Label('transport', MetricLabelTypeEnum::STRING, 'Transport (receiver) name');
        $message = new Label('message', MetricLabelTypeEnum::STRING, 'Message short class name');

        return match ($this) {
            self::MESSAGE_SENT => [$transport, $message],
            self::MESSAGE_HANDLED,
            self::MESSAGE_HANDLING_DURATION_HISTOGRAM_SECONDS => [
                $transport,
                $message,
                new Label(
                    'status',
                    MetricLabelTypeEnum::ENUM,
                    'Handling outcome',
                    array_map(static fn (MessengerMessageStatusEnum $status): string => $status->value, MessengerMessageStatusEnum::cases()),
                ),
            ],
        };
    }

    #[Override]
    public function getBatches(): array
    {
        return match ($this) {
            self::MESSAGE_HANDLING_DURATION_HISTOGRAM_SECONDS => [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10, 30, 60, 180],
            default => [],
        };
    }
}
