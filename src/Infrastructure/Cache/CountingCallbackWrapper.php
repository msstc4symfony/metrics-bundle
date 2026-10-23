<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Cache;

use Closure;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\CacheItem;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * A pool's callback wrapper (ContractsTrait::setCallbackWrapper()) that counts the computations the pool makes. The
 * pool calls it only when it computes a value, so the application's callback reaches the pool unchanged and the
 * early-expiration dispatcher can still reverse it for an asynchronous recomputation. Placed inside that dispatcher,
 * it does not count a recomputation sent to the bus.
 *
 * @phpstan-type ComputeCallback callable(ItemInterface, bool): mixed
 * @phpstan-type SetMetadata Closure(CacheItem): void
 * @phpstan-type CallbackWrapper callable(ComputeCallback, ItemInterface, bool, AdapterInterface, SetMetadata, ?LoggerInterface, ?float): mixed
 *
 * @internal wired by DecorateCachePoolsPass
 */
final readonly class CountingCallbackWrapper
{
    /** @var Closure(ComputeCallback, ItemInterface, bool, AdapterInterface, SetMetadata, ?LoggerInterface, ?float): mixed */
    private Closure $inner;

    /**
     * @param CallbackWrapper $inner the wrapper the pool had before
     */
    public function __construct(
        callable $inner,
        private ComputationCounter $computations,
    ) {
        $this->inner = $inner(...);
    }

    /**
     * @param ComputeCallback $callback
     * @param SetMetadata $setMetadata
     */
    public function __invoke(callable $callback, ItemInterface $item, bool &$save, AdapterInterface $pool, Closure $setMetadata, ?LoggerInterface $logger = null, ?float $beta = null): mixed
    {
        $this->computations->record();

        return ($this->inner)($callback, $item, $save, $pool, $setMetadata, $logger, $beta);
    }
}
