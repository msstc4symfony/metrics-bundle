<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Application\Port;

use InvalidArgumentException;
use Msstc4Symfony\MetricsBundle\Application\Metric\MessengerMessageStatus;
use Throwable;

/**
 * New fields are added only as optional trailing constructor parameters.
 */
final readonly class MessengerMeasurement
{
    /**
     * @param non-empty-string $transport Receiver name the message was consumed from (the failure transport for messenger:failed:retry)
     * @param class-string $messageClass
     * @param float|null $durationSeconds Same value that was written to the duration metrics; null when no start was seen
     * @param Throwable|null $exception Raw throwable of WorkerMessageFailedEvent (usually HandlerFailedException), null for handled messages
     */
    public function __construct(
        public string $transport,
        public string $messageClass,
        public MessengerMessageStatus $status,
        public ?float $durationSeconds,
        public ?Throwable $exception,
    ) {
        $this->assertTransport($transport);

        if ($durationSeconds !== null && $durationSeconds < 0.0) {
            throw new InvalidArgumentException(\sprintf('Duration must not be negative, %F given.', $durationSeconds));
        }
    }

    // Kept apart from the constructor: its narrowed @param would let Rector drop the check as dead code.
    private function assertTransport(string $transport): void
    {
        if ($transport === '') {
            throw new InvalidArgumentException('Messenger transport must not be empty.');
        }
    }
}
