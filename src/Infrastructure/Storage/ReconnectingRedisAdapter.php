<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Storage;

use Closure;
use Override;
use Prometheus\Exception\StorageException;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\Redis;
use Prometheus\Storage\RedisClients\RedisClientException;
use Prometheus\Storage\RedisNg;
use RedisException;
use Throwable;

/**
 * Once phpredis loses a connection it marks the client as failed and throws "Redis server ... went
 * away" on every later command, even after Redis is back, while the promphp adapter never
 * reconnects. After a connection failure the next operation runs on a freshly built adapter, which
 * connects, authenticates, selects the database and applies the options again.
 *
 * @internal
 */
final class ReconnectingRedisAdapter implements Adapter
{
    private Redis|RedisNg|null $adapter;

    /**
     * @param Closure(): (Redis|RedisNg) $connect
     */
    public function __construct(
        private readonly Closure $connect,
    ) {
        // Eager: a construction error (no ext-redis, no RedisNg) must reach Factory::create() and its InMemory fallback.
        $this->adapter = ($this->connect)();
    }

    #[Override]
    public function collect(bool $sortMetrics = true): array
    {
        try {
            return $this->connected()->collect($sortMetrics);
        } catch (Throwable $exception) {
            $this->forgetConnectionOn($exception);

            throw $exception;
        }
    }

    #[Override]
    public function updateSummary(array $data): void
    {
        $this->write(static fn (Redis|RedisNg $adapter) => $adapter->updateSummary($data));
    }

    #[Override]
    public function updateHistogram(array $data): void
    {
        $this->write(static fn (Redis|RedisNg $adapter) => $adapter->updateHistogram($data));
    }

    #[Override]
    public function updateGauge(array $data): void
    {
        $this->write(static fn (Redis|RedisNg $adapter) => $adapter->updateGauge($data));
    }

    #[Override]
    public function updateCounter(array $data): void
    {
        $this->write(static fn (Redis|RedisNg $adapter) => $adapter->updateCounter($data));
    }

    #[Override]
    public function wipeStorage(): void
    {
        $this->write(static fn (Redis|RedisNg $adapter) => $adapter->wipeStorage());
    }

    /**
     * @param Closure(Redis|RedisNg): void $operation
     */
    private function write(Closure $operation): void
    {
        try {
            $operation($this->connected());
        } catch (Throwable $exception) {
            $this->forgetConnectionOn($exception);

            throw $exception;
        }
    }

    private function connected(): Redis|RedisNg
    {
        return $this->adapter ??= ($this->connect)();
    }

    private function forgetConnectionOn(Throwable $exception): void
    {
        if ($exception instanceof RedisException
            || $exception instanceof RedisClientException
            || $exception instanceof StorageException
        ) {
            $this->adapter = null;
        }
    }
}
