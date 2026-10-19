<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Kernel;

use Override;
use Prometheus\Exception\StorageException;
use Prometheus\Storage\InMemory;

final class FailingCounterStorage extends InMemory
{
    /**
     * @param array<mixed> $data
     */
    #[Override]
    public function updateCounter(array $data): void
    {
        throw new StorageException('storage down');
    }
}
