<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Application\Port;

use Throwable;

/**
 * @internal the Messenger adapter is the only implementation; not part of the public API
 */
interface QueueDepthReaderInterface
{
    /**
     * @return non-negative-int|null null when the transport cannot count its messages
     *
     * @throws Throwable when the transport fails to count them (broker down)
     */
    public function messageCount(string $transport): ?int;
}
