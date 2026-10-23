<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Cache;

use BadMethodCallException;
use Generator;
use Override;
use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\CallbackInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\NamespacedPoolInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

/**
 * Forwards every call to $this->pool and counts item lookups in $this->collector under $this->poolName: getItem(),
 * getItems() (per item) and get() (a miss when the pool computes the value: through the pool's CountingCallbackWrapper when
 * it has one, else by wrapping the callback). hasItem() is not counted.
 *
 * @internal
 */
trait CountsCacheLookups
{
    // A TagAwareAdapter on top of a counted pool (the ".<pool>.taggable" service) reads tag versions and the tags of
    // items stored before Symfony 6.1 through it: those are not lookups of the application.
    private const LEGACY_ITEM_TAGS_PREFIX = "\0tags\0";

    #[Override]
    public function getItem(mixed $key): CacheItem
    {
        $item = $this->pool->getItem($key);
        $this->count($key, $item->isHit());

        return $item;
    }

    /**
     * @param array<array-key, string> $keys
     *
     * @return iterable<string, CacheItem>
     */
    #[Override]
    public function getItems(array $keys = []): iterable
    {
        // Called eagerly, as a bare pool would be: invalid keys throw here and deferred items get committed.
        $items = $this->pool->getItems($keys);
        if (!\is_array($items)) {
            return $this->countWhileIterating($items);
        }

        foreach ($items as $key => $item) {
            $this->count($key, $item->isHit());
        }

        return $items;
    }

    #[Override]
    public function hasItem(string $key): bool
    {
        return $this->pool->hasItem($key);
    }

    #[Override]
    public function clear(string $prefix = ''): bool
    {
        return $this->pool->clear($prefix);
    }

    #[Override]
    public function deleteItem(string $key): bool
    {
        return $this->pool->deleteItem($key);
    }

    /**
     * @param array<array-key, string> $keys
     */
    #[Override]
    public function deleteItems(array $keys): bool
    {
        return $this->pool->deleteItems($keys);
    }

    #[Override]
    public function save(CacheItemInterface $item): bool
    {
        return $this->pool->save($item);
    }

    #[Override]
    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->pool->saveDeferred($item);
    }

    #[Override]
    public function commit(): bool
    {
        return $this->pool->commit();
    }

    /**
     * @template T of mixed
     *
     * @param callable(CacheItemInterface, bool):T|callable(ItemInterface, bool):T|CallbackInterface<T> $callback
     * @param array<array-key, mixed>|null $metadata
     *
     * @return T
     */
    #[Override]
    public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
    {
        if (!$this->pool instanceof CacheInterface) {
            throw new BadMethodCallException(\sprintf('Cannot call "%s::get()": this class doesn\'t implement "%s".', get_debug_type($this->pool), CacheInterface::class));
        }

        $computations = $this->computations ?? new ComputationCounter();
        $before = $computations->count();
        if ($this->computations === null) {
            // Without a callback wrapper on the pool, only wrapping the callback tells a computation apart.
            $callback = static function (ItemInterface $item, bool &$save) use ($callback, $computations): mixed {
                $computations->record();

                return $callback($item, $save);
            };
        }

        try {
            $value = $this->pool->get($key, $callback, $beta, $metadata);
        } catch (Throwable $exception) {
            if ($computations->count() !== $before) {
                $this->count($key, false);
            }

            throw $exception;
        }

        $this->count($key, $computations->count() === $before);

        return $value;
    }

    #[Override]
    public function delete(string $key): bool
    {
        return $this->pool->deleteItem($key);
    }

    #[Override]
    public function withSubNamespace(string $namespace): static
    {
        if (!$this->pool instanceof NamespacedPoolInterface) {
            throw new BadMethodCallException(\sprintf('Cannot call "%s::withSubNamespace()": this class doesn\'t implement "%s".', get_debug_type($this->pool), NamespacedPoolInterface::class));
        }

        return new self($this->pool->withSubNamespace($namespace), $this->poolName, $this->collector, $this->computations);
    }

    #[Override]
    public function prune(): bool
    {
        return $this->pool instanceof PruneableInterface && $this->pool->prune();
    }

    #[Override]
    public function reset(): void
    {
        if ($this->pool instanceof ResetInterface) {
            $this->pool->reset();
        }
    }

    /**
     * @param iterable<string, CacheItem> $items
     *
     * @return Generator<string, CacheItem, mixed, void>
     */
    private function countWhileIterating(iterable $items): Generator
    {
        foreach ($items as $key => $item) {
            $this->count($key, $item->isHit());

            yield $key => $item;
        }
    }

    private function count(mixed $key, bool $hit): void
    {
        if (\is_string($key) && (str_ends_with($key, TagAwareAdapter::TAGS_PREFIX) || str_starts_with($key, self::LEGACY_ITEM_TAGS_PREFIX))) {
            return;
        }

        $this->collector->recordLookup($this->poolName, $hit);
    }
}
