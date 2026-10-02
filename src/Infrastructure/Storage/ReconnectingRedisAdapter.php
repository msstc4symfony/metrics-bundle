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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RedisException;
use Throwable;

/**
 * Once phpredis loses a connection it marks the client as failed and throws "Redis server ... went
 * away" on every later command, even after Redis is back, while the promphp adapter never
 * reconnects. After a connection failure the next operation runs on a freshly built adapter, which
 * connects, authenticates, selects the database and applies the options again.
 *
 * Metrics must never break the application: a failed write is dropped and logged, a failed read
 * becomes a StorageException, and PHP warnings raised by phpredis (e.g. DNS failures in connect())
 * never reach the application's error handler, which may turn them into exceptions.
 *
 * @internal
 */
final class ReconnectingRedisAdapter implements Adapter
{
    private const int CAPTURED_ERRORS = \E_WARNING | \E_NOTICE | \E_USER_WARNING | \E_USER_NOTICE;

    private const float REPORT_INTERVAL_SECONDS = 60.0;

    private Redis|RedisNg|null $adapter;

    private ?float $lastReportAt = null;

    /** Samples dropped since the last report. */
    private int $dropped = 0;

    /** @var Closure(): float */
    private readonly Closure $clock;

    /**
     * @param Closure(): (Redis|RedisNg) $connect
     * @param (Closure(): float)|null $clock monotonic seconds
     */
    public function __construct(
        private readonly Closure $connect,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
        // Eager: a construction error (no ext-redis, no RedisNg) must reach Factory::create() and its InMemory fallback.
        $this->adapter = ($this->connect)();
    }

    /**
     * @throws StorageException
     */
    #[Override]
    public function collect(bool $sortMetrics = true): array
    {
        $samples = [];
        $this->guard(__FUNCTION__, static function (Redis|RedisNg $adapter) use ($sortMetrics, &$samples): void {
            $samples = $adapter->collect($sortMetrics);
        });

        return $samples;
    }

    #[Override]
    public function updateSummary(array $data): void
    {
        $this->write(__FUNCTION__, static fn (Redis|RedisNg $adapter) => $adapter->updateSummary($data));
    }

    #[Override]
    public function updateHistogram(array $data): void
    {
        $this->write(__FUNCTION__, static fn (Redis|RedisNg $adapter) => $adapter->updateHistogram($data));
    }

    #[Override]
    public function updateGauge(array $data): void
    {
        $this->write(__FUNCTION__, static fn (Redis|RedisNg $adapter) => $adapter->updateGauge($data));
    }

    #[Override]
    public function updateCounter(array $data): void
    {
        $this->write(__FUNCTION__, static fn (Redis|RedisNg $adapter) => $adapter->updateCounter($data));
    }

    /**
     * @throws StorageException
     */
    #[Override]
    public function wipeStorage(): void
    {
        $this->guard(__FUNCTION__, static fn (Redis|RedisNg $adapter) => $adapter->wipeStorage());
    }

    /**
     * @param non-empty-string $name
     * @param Closure(Redis|RedisNg): void $operation
     */
    private function write(string $name, Closure $operation): void
    {
        try {
            $this->guard($name, $operation);
        } catch (StorageException $exception) {
            $this->recordDrop($exception);

            return;
        }

        if ($this->lastReportAt !== null) {
            $this->log('info', 'metrics-bundle: metric storage is writable again, ' . $this->dropped . ' samples dropped since the last report');
            $this->lastReportAt = null;
            $this->dropped = 0;
        }
    }

    /**
     * An outage drops every sample: the first failure is logged in full, the rest are summarised
     * once per interval so a long outage does not flood the logs.
     */
    private function recordDrop(StorageException $exception): void
    {
        $this->dropped++;
        $now = ($this->clock)();

        if ($this->lastReportAt === null) {
            $this->log('error', 'metrics-bundle: metric storage write failed, samples are dropped until it recovers', ['exception' => $exception]);
        } elseif ($now - $this->lastReportAt >= self::REPORT_INTERVAL_SECONDS) {
            $this->log('warning', 'metrics-bundle: metric storage still failing, ' . $this->dropped . ' samples dropped since the last report', ['exception' => $exception]);
        } else {
            return;
        }

        $this->lastReportAt = $now;
        $this->dropped = 0;
    }

    /**
     * @param 'debug'|'info'|'warning'|'error' $level
     * @param array<string, Throwable> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        try {
            $this->logger->log($level, $message, $context);
        } catch (Throwable) {
            // Logger failures must not break the application.
        }
    }

    /**
     * @param non-empty-string $name
     * @param Closure(Redis|RedisNg): void $operation
     *
     * @throws StorageException
     */
    private function guard(string $name, Closure $operation): void
    {
        $lastWarning = null;
        $previous = null;
        $previous = set_error_handler(static function (int $type, string $message, string $file, int $line) use (&$lastWarning, &$previous): bool {
            if (($type & self::CAPTURED_ERRORS) !== 0) {
                $lastWarning = $message;

                return true;
            }

            // Deprecations and the like still belong to the application's handler; types silenced by
            // error_reporting() are not passed on, as PHP would not have called it for them either.
            return $previous !== null
                && (error_reporting() & $type) !== 0
                && $previous($type, $message, $file, $line) !== false;
        });

        try {
            $operation($this->adapter ??= ($this->connect)());
        } catch (Throwable $exception) {
            if ($exception instanceof RedisException
                || $exception instanceof RedisClientException
                || $exception instanceof StorageException
            ) {
                $this->adapter = null;
            }

            $message = 'Metric storage ' . $name . ' failed: ' . $exception->getMessage();

            throw new StorageException($lastWarning === null ? $message : $message . ' (' . $lastWarning . ')', 0, $exception);
        } finally {
            restore_error_handler();
        }

        if ($lastWarning !== null) {
            $this->log('debug', 'metrics-bundle: metric storage ' . $name . ' succeeded with a warning: ' . $lastWarning);
        }
    }
}
