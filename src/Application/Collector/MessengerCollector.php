<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Application\Collector;

use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Application\Metric\MessengerMessageStatus;
use Msstc4Symfony\MetricsBundle\Application\Port\MetricRecorderInterface;

/**
 * @internal
 */
final readonly class MessengerCollector
{
    private const ANONYMOUS_CLASS_MARKER = '@anonymous';

    public function __construct(
        private MetricRecorderInterface $recorder,
    ) {
    }

    /**
     * @param class-string $messageClass
     */
    public function incMessageSent(string $transport, string $messageClass): void
    {
        $this->recorder->increment(BuiltInMetric::MESSENGER_MESSAGE_SENT, [$transport, $this->formatMessageClass($messageClass)]);
    }

    /**
     * @param class-string $messageClass
     */
    public function incMessageHandled(string $transport, string $messageClass, MessengerMessageStatus $status): void
    {
        $this->recorder->increment(
            BuiltInMetric::MESSENGER_MESSAGE_HANDLED,
            [$transport, $this->formatMessageClass($messageClass), $status->value],
        );
    }

    /**
     * @param class-string $messageClass
     */
    public function observeHandlingDuration(
        string $transport,
        string $messageClass,
        MessengerMessageStatus $status,
        float $duration,
    ): void {
        $this->recorder->observe(
            BuiltInMetric::MESSENGER_MESSAGE_HANDLING_DURATION_HISTOGRAM_SECONDS,
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
