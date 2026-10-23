<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Support;

use Msstc4Symfony\MetricsBundle\Application\Port\QueueDepthReaderInterface;
use Override;
use Throwable;

final class StubQueueDepthReader implements QueueDepthReaderInterface
{
    /** @var list<string> */
    public array $reads = [];

    /**
     * @param array<string, non-negative-int|Throwable|null> $depths
     */
    public function __construct(
        public array $depths,
    ) {
    }

    #[Override]
    public function messageCount(string $transport): ?int
    {
        $this->reads[] = $transport;
        $depth = $this->depths[$transport] ?? null;
        if ($depth instanceof Throwable) {
            throw $depth;
        }

        return $depth;
    }
}
