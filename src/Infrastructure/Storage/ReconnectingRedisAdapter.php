<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Storage;

use Closure;
use InvalidArgumentException;
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
    public const float DEFAULT_BACKOFF_SECONDS = 5.0;

    private const int CAPTURED_ERRORS = \E_WARNING | \E_NOTICE | \E_USER_WARNING | \E_USER_NOTICE;

    private const float REPORT_INTERVAL_SECONDS = 60.0;

    private Redis|RedisNg|null $adapter;

    private ?float $lastReportAt = null;

    /** Samples dropped since the last report. */
    private int $dropped = 0;

    /** @var Closure(): float */
    private readonly Closure $clock;

    /** Whether the current promphp adapter has completed a call, i.e. its handshake is done. */
    private bool $connected = false;

    /** Circuit breaker: no reconnect attempt before this monotonic time. */
    private ?float $retryAt = null;

    /**
     * @param Closure(): (Redis|RedisNg) $connect
     * @param (Closure(): float)|null $clock monotonic seconds
     * @param float $backoffSeconds 0 reconnects on every operation
     * @param float|null $handshakeTimeout caps default_socket_timeout while a fresh connection is opened
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        private readonly Closure $connect,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?Closure $clock = null,
        private readonly float $backoffSeconds = self::DEFAULT_BACKOFF_SECONDS,
        private readonly ?float $handshakeTimeout = null,
    ) {
        if ($backoffSeconds < 0) {
            throw new InvalidArgumentException('The reconnect backoff must be >= 0, ' . $backoffSeconds . ' given.');
        }

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
        if ($this->retryAt !== null && ($this->clock)() < $this->retryAt) {
            throw new StorageException('Metric storage ' . $name . ' skipped: it failed less than ' . $this->backoffSeconds . ' s ago');
        }

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

        // promphp sends AUTH/SELECT before it applies read_timeout, so a server that accepts but never
        // answers would hold a fresh handshake for default_socket_timeout (60 s by default).
        $socketTimeout = $this->connected ? false : $this->lowerSocketTimeoutForHandshake();

        try {
            $operation($this->adapter ??= ($this->connect)());
            $this->connected = true;
            $this->retryAt = null;
        } catch (Throwable $exception) {
            if ($exception instanceof RedisException
                || $exception instanceof RedisClientException
                || $exception instanceof StorageException
            ) {
                // Each attempt against a dead host costs a DNS lookup or a connect timeout.
                $this->adapter = null;
                $this->connected = false;
                $this->retryAt = $this->backoffSeconds > 0 ? ($this->clock)() + $this->backoffSeconds : null;
            } else {
                $this->retryAt = null;
            }

            $message = 'Metric storage ' . $name . ' failed: ' . $exception->getMessage();

            throw new StorageException($lastWarning === null ? $message : $message . ' (' . $lastWarning . ')', 0, $exception);
        } finally {
            if ($socketTimeout !== false) {
                ini_set('default_socket_timeout', $socketTimeout);
            }

            restore_error_handler();
        }

        if ($lastWarning !== null) {
            $this->log('debug', 'metrics-bundle: metric storage ' . $name . ' succeeded with a warning: ' . $lastWarning);
        }
    }

    /**
     * @return string|false the previous default_socket_timeout, false when it was left alone
     */
    private function lowerSocketTimeoutForHandshake(): string|false
    {
        if ($this->handshakeTimeout === null || $this->handshakeTimeout <= 0) {
            return false;
        }

        $cap = max(1, (int) ceil($this->handshakeTimeout));
        $current = (int) ini_get('default_socket_timeout');
        if ($current > 0 && $current <= $cap) {
            return false;
        }

        return ini_set('default_socket_timeout', (string) $cap);
    }
}
