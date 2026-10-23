<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Messenger;

use Msstc4Symfony\MetricsBundle\Application\Port\QueueDepthReaderInterface;
use Override;
use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;

/**
 * @internal registered by RegisterMessengerQueueDepthPass
 */
final readonly class MessengerQueueDepthReader implements QueueDepthReaderInterface
{
    /**
     * @param ContainerInterface $receivers Messenger receivers by transport name
     */
    public function __construct(
        private ContainerInterface $receivers,
    ) {
    }

    #[Override]
    public function messageCount(string $transport): ?int
    {
        $receiver = $this->receivers->get($transport);

        return $receiver instanceof MessageCountAwareInterface ? max(0, $receiver->getMessageCount()) : null;
    }
}
