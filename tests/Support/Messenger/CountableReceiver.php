<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Support\Messenger;

use Override;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

/**
 * A receiver that counts its waiting messages: the in-memory transport cannot. A null count makes
 * getMessageCount() fail like an unreachable broker.
 */
final class CountableReceiver implements ReceiverInterface, MessageCountAwareInterface
{
    public function __construct(
        public ?int $messageCount = 0,
    ) {
    }

    #[Override]
    public function getMessageCount(): int
    {
        return $this->messageCount ?? throw new RuntimeException('broker unreachable');
    }

    /**
     * @return list<Envelope>
     */
    #[Override]
    public function get(): iterable
    {
        return [];
    }

    #[Override]
    public function ack(Envelope $envelope): void
    {
    }

    #[Override]
    public function reject(Envelope $envelope): void
    {
    }
}
