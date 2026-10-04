<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Collector;

use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MessengerMessageStatusEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;

final class MessengerCollector extends AbstractCollector
{
    private const string ANONYMOUS_CLASS_MARKER = '@anonymous';

    /**
     * @param class-string $messageClass
     */
    public function incMessageSent(string $transport, string $messageClass): void
    {
        $this->incCounter(MetricLabelEnum::MESSENGER_MESSAGE_SENT, [$transport, $this->formatMessageClass($messageClass)]);
    }

    /**
     * @param class-string $messageClass
     */
    public function incMessageHandled(string $transport, string $messageClass, MessengerMessageStatusEnum $status): void
    {
        $this->incCounter(
            MetricLabelEnum::MESSENGER_MESSAGE_HANDLED,
            [$transport, $this->formatMessageClass($messageClass), $status->value],
        );
    }

    /**
     * @param class-string $messageClass
     */
    public function observeHandlingDuration(
        string $transport,
        string $messageClass,
        MessengerMessageStatusEnum $status,
        float $duration,
    ): void {
        $this->observeHistogram(
            MetricLabelEnum::MESSENGER_MESSAGE_HANDLING_DURATION_HISTOGRAM_SECONDS,
            $duration,
            [$transport, $this->formatMessageClass($messageClass), $status->value],
        );
    }

    /**
     * Anonymous class names embed the file path and a per-declaration suffix; cut them so
     * each anonymous message type yields one bounded label value.
     */
    private function formatMessageClass(string $class): string
    {
        $anonymous = strpos($class, self::ANONYMOUS_CLASS_MARKER);
        if ($anonymous !== false) {
            $class = substr($class, 0, $anonymous + \strlen(self::ANONYMOUS_CLASS_MARKER));
        }

        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }
}
