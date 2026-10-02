<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Infrastructure\Storage;

use Closure;
use Error;
use ErrorException;
use InvalidArgumentException;
use Msstc4Symfony\MetricsBundle\Infrastructure\Storage\ReconnectingRedisAdapter;
use Msstc4Symfony\MetricsBundle\Test\Support\CollectingLogger;
use Msstc4Symfony\MetricsBundle\Test\Support\Redis\FailingOnceDownRedis;
use Msstc4Symfony\MetricsBundle\Test\Support\Redis\FakeRedisServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prometheus\Exception\StorageException;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\Redis;
use Prometheus\Storage\RedisClients\RedisClientException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class ReconnectingRedisAdapterTest extends TestCase
{
    private FakeRedisServer $server;

    private int $connections = 0;

    private CollectingLogger $logger;

    private float $now = 1000.0;

    protected function setUp(): void
    {
        if (version_compare((string) phpversion('redis'), '6.0.0', '<')) {
            self::markTestSkipped('FailingOnceDownRedis overrides the typed phpredis 6 signatures');
        }

        $this->server = new FakeRedisServer();
        $this->connections = 0;
        $this->logger = new CollectingLogger();
    }

    /**
     * @param Closure(Adapter): void $operation
     */
    #[DataProvider('provideOperations')]
    public function testOperationReconnectsAfterRedisWentAway(Closure $operation, string $command, bool $read): void
    {
        $adapter = new ReconnectingRedisAdapter($this->connect(...), $this->logger, $this->clock(...), backoffSeconds: 0.0);
        $operation($adapter);

        $this->server->up = false;
        $this->runWhileDown($operation, $adapter, $read);

        $this->server->up = true;
        $sentBeforeRecovery = \count($this->server->commands);
        $operation($adapter);

        self::assertSame($command, $this->server->commands[$sentBeforeRecovery] ?? null);
        self::assertSame(2, $this->connections);
    }

    /**
     * @return iterable<string, array{Closure(Adapter): void, string, bool}>
     */
    public static function provideOperations(): iterable
    {
        yield 'counter' => [static fn (Adapter $adapter) => $adapter->updateCounter(self::counter()), 'EVAL', false];
        yield 'gauge' => [static fn (Adapter $adapter) => $adapter->updateGauge(self::gauge()), 'EVAL', false];
        yield 'histogram' => [static fn (Adapter $adapter) => $adapter->updateHistogram(self::histogram()), 'EVAL', false];
        yield 'summary' => [static fn (Adapter $adapter) => $adapter->updateSummary(self::summary()), 'SETNX', false];
        yield 'collect' => [static function (Adapter $adapter): void {
            $adapter->collect();
        }, 'SMEMBERS', true];
        yield 'wipe storage' => [static fn (Adapter $adapter) => $adapter->wipeStorage(), 'EVAL', true];
    }

    /**
     * @param Closure(Adapter): void $operation
     */
    #[DataProvider('provideOperations')]
    public function testWarningWhileRedisIsDownNeverReachesTheApplicationErrorHandler(Closure $operation, string $command, bool $read): void
    {
        $adapter = new ReconnectingRedisAdapter($this->connect(...), $this->logger, $this->clock(...), backoffSeconds: 0.0);
        $this->server->up = false;
        $this->server->warningWhenDown = 'Redis::connect(): php_network_getaddresses: getaddrinfo for redis failed: Name or service not known';

        $handled = 0;
        set_error_handler(static function (int $type, string $message, string $file, int $line) use (&$handled): never {
            $handled++;

            throw new ErrorException($message, 0, $type, $file, $line);
        });

        try {
            $this->runWhileDown($operation, $adapter, $read);
        } finally {
            restore_error_handler();
        }

        self::assertSame(0, $handled);
    }

    public function testOutageIsLoggedOnceThenSummarisedAndRecoveryIsLogged(): void
    {
        $adapter = new ReconnectingRedisAdapter($this->connect(...), $this->logger, $this->clock(...), backoffSeconds: 0.0);
        $this->server->up = false;

        $adapter->updateCounter(self::counter());
        $adapter->updateCounter(self::counter());
        $this->now += 30;
        $adapter->updateCounter(self::counter());
        self::assertCount(1, $this->logger->records, 'Repeated drops within the interval are not logged one by one.');
        self::assertStringStartsWith('error: ', $this->logger->records[0]);

        $this->now += 31;
        $adapter->updateCounter(self::counter());
        self::assertCount(2, $this->logger->records);
        self::assertStringStartsWith('warning: ', $this->logger->records[1]);
        self::assertStringContainsString('3 samples dropped', $this->logger->records[1]);

        $this->server->up = true;
        $adapter->updateCounter(self::counter());
        self::assertCount(3, $this->logger->records);
        self::assertStringStartsWith('info: ', $this->logger->records[2]);

        $this->server->up = false;
        $adapter->updateCounter(self::counter());
        self::assertCount(4, $this->logger->records, 'A new outage is logged again.');
        self::assertStringStartsWith('error: ', $this->logger->records[3]);
    }

    public function testDeprecationDuringAnOperationStillReachesTheApplicationHandler(): void
    {
        $adapter = new ReconnectingRedisAdapter($this->connect(...), $this->logger, $this->clock(...), backoffSeconds: 0.0);
        $this->server->deprecation = 'Some option is deprecated';

        $seen = [];
        set_error_handler(static function (int $type, string $message) use (&$seen): bool {
            $seen[] = $message;

            return true;
        });

        $reporting = error_reporting(\E_ALL);

        try {
            $adapter->updateCounter(self::counter());
        } finally {
            error_reporting($reporting);
            restore_error_handler();
        }

        self::assertSame(['Some option is deprecated'], $seen);
        self::assertSame(['EVAL'], $this->server->commands);
        self::assertSame([], $this->logger->records);
    }

    public function testWarningOnASuccessfulOperationIsLoggedAtDebugOnly(): void
    {
        $adapter = new ReconnectingRedisAdapter($this->connect(...), $this->logger, $this->clock(...), backoffSeconds: 0.0);
        $this->server->notice = 'Serializer fallback used';

        $handled = 0;
        set_error_handler(static function () use (&$handled): bool {
            $handled++;

            return true;
        });

        try {
            $adapter->updateCounter(self::counter());
        } finally {
            restore_error_handler();
        }

        self::assertSame(0, $handled);
        self::assertSame(['EVAL'], $this->server->commands);
        self::assertCount(1, $this->logger->records);
        self::assertStringStartsWith('debug: ', $this->logger->records[0]);
    }

    public function testThrowingLoggerNeverEscapesAWrite(): void
    {
        $logger = self::createStub(LoggerInterface::class);
        $logger->method('log')->willThrowException(new RuntimeException('log handler is down'));
        $adapter = new ReconnectingRedisAdapter($this->connect(...), $logger, $this->clock(...), backoffSeconds: 0.0);
        $this->server->up = false;

        $this->expectNotToPerformAssertions();
        $adapter->updateCounter(self::counter());
    }

    public function testEveryOperationWhileRedisIsDownTriesOneFreshConnection(): void
    {
        $adapter = new ReconnectingRedisAdapter($this->connect(...), $this->logger, $this->clock(...), backoffSeconds: 0.0);
        $this->server->up = false;

        for ($i = 0; $i < 3; $i++) {
            $adapter->updateCounter(self::counter());
        }

        self::assertSame(3, $this->connections);
    }

    public function testNonConnectionFailureIsDroppedButKeepsTheConnection(): void
    {
        $adapter = new ReconnectingRedisAdapter($this->connect(...), $this->logger, $this->clock(...), backoffSeconds: 0.0);

        // promphp encodes summary label values with a RuntimeException on invalid UTF-8.
        $adapter->updateSummary(['labelNames' => ['l'], 'labelValues' => ["\xB1"]] + self::summary());
        $adapter->updateCounter(self::counter());

        self::assertStringStartsWith('error: ', $this->logger->records[0] ?? '');
        self::assertSame(['SETNX', 'EVAL'], $this->server->commands);
        self::assertSame(1, $this->connections);
    }

    /**
     * @param class-string<Throwable> $error
     */
    #[DataProvider('provideConnectionErrors')]
    public function testClientLevelConnectionErrorAlsoReconnects(string $error): void
    {
        if (!class_exists($error)) {
            self::markTestSkipped($error . ' needs promphp/prometheus_client_php 2.15+');
        }

        $adapter = new ReconnectingRedisAdapter($this->connect(...), $this->logger, $this->clock(...), backoffSeconds: 0.0);
        $this->server->nextError = new $error('Connection lost');

        $adapter->updateCounter(self::counter());
        $adapter->updateCounter(self::counter());

        self::assertSame(2, $this->connections);
    }

    /**
     * @return iterable<string, array{class-string<Throwable>}>
     */
    public static function provideConnectionErrors(): iterable
    {
        yield 'storage exception' => [StorageException::class];
        yield 'redis client exception' => [RedisClientException::class];
    }

    public function testNoReconnectAttemptWithinTheBackoffWindow(): void
    {
        $adapter = new ReconnectingRedisAdapter($this->connect(...), $this->logger, $this->clock(...), backoffSeconds: 5.0);
        $this->server->up = false;

        $adapter->updateCounter(self::counter());
        $this->now += 4.9;
        for ($i = 0; $i < 50; $i++) {
            $adapter->updateCounter(self::counter());
        }

        try {
            $adapter->collect();
            self::fail('A read inside the backoff window must report the storage as unavailable.');
        } catch (StorageException) {
        }

        self::assertSame(1, $this->connections, 'Only the adapter built in the constructor; no reconnect inside the window.');
        self::assertCount(1, $this->logger->records);
    }

    public function testWipeStorageInsideTheWindowFailsWithoutConnecting(): void
    {
        $adapter = new ReconnectingRedisAdapter($this->connect(...), $this->logger, $this->clock(...), backoffSeconds: 5.0);
        $this->server->up = false;
        $adapter->updateCounter(self::counter());
        $this->server->up = true;

        try {
            $adapter->wipeStorage();
            self::fail('wipeStorage() inside the window must fail.');
        } catch (StorageException) {
        }

        self::assertSame(1, $this->connections);
        self::assertSame([], $this->server->commands);
    }

    public function testHandshakeNeverRaisesDefaultSocketTimeout(): void
    {
        $adapter = new ReconnectingRedisAdapter($this->connect(...), $this->logger, $this->clock(...), handshakeTimeout: 120.0);
        $seen = null;
        $this->server->onCommand = static function () use (&$seen): void {
            $seen = ini_get('default_socket_timeout');
        };
        $timeout = ini_set('default_socket_timeout', '3');

        try {
            $adapter->updateCounter(self::counter());
        } finally {
            ini_set('default_socket_timeout', (string) $timeout);
        }

        self::assertSame('3', $seen);
    }

    public function testHandshakeLowersDefaultSocketTimeoutToTheReadTimeout(): void
    {
        $adapter = new ReconnectingRedisAdapter($this->connect(...), $this->logger, $this->clock(...), handshakeTimeout: 0.5);
        $seen = null;
        $this->server->onCommand = static function () use (&$seen): void {
            $seen = ini_get('default_socket_timeout');
        };
        $timeout = ini_set('default_socket_timeout', '60');

        try {
            $adapter->updateCounter(self::counter());
            $after = ini_get('default_socket_timeout');
        } finally {
            ini_set('default_socket_timeout', (string) $timeout);
        }

        self::assertSame('1', $seen);
        self::assertSame('60', $after);
    }

    public function testNegativeBackoffIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReconnectingRedisAdapter($this->connect(...), $this->logger, $this->clock(...), backoffSeconds: -1.0);
    }

    public function testOneAttemptAfterTheWindowThenTheBreakerOpensAgain(): void
    {
        $adapter = new ReconnectingRedisAdapter($this->connect(...), $this->logger, $this->clock(...), backoffSeconds: 5.0);
        $this->server->up = false;
        $adapter->updateCounter(self::counter());

        $this->now += 5.0;
        $adapter->updateCounter(self::counter());
        $adapter->updateCounter(self::counter());

        self::assertSame(2, $this->connections);
    }

    public function testSuccessfulAttemptAfterTheWindowClosesTheBreaker(): void
    {
        $adapter = new ReconnectingRedisAdapter($this->connect(...), $this->logger, $this->clock(...), backoffSeconds: 5.0);
        $this->server->up = false;
        $adapter->updateCounter(self::counter());
        $this->server->up = true;

        $adapter->updateCounter(self::counter());
        self::assertSame([], $this->server->commands, 'Still inside the window: dropped without a network call.');

        $this->now += 5.0;
        $adapter->updateCounter(self::counter());
        $adapter->updateCounter(self::counter());

        self::assertSame(['EVAL', 'EVAL'], $this->server->commands);
        self::assertSame(2, $this->connections);
        self::assertStringStartsWith('info: ', $this->logger->records[1] ?? '');
    }

    public function testNonConnectionFailureDoesNotOpenTheBreaker(): void
    {
        $adapter = new ReconnectingRedisAdapter($this->connect(...), $this->logger, $this->clock(...), backoffSeconds: 5.0);

        $adapter->updateSummary(['labelNames' => ['l'], 'labelValues' => ["\xB1"]] + self::summary());
        $adapter->updateCounter(self::counter());

        self::assertSame(['SETNX', 'EVAL'], $this->server->commands);
    }

    public function testConnectsEagerlySoTheFactoryCanFallBackOnConstructionErrors(): void
    {
        $this->expectException(Error::class);

        new ReconnectingRedisAdapter(static fn (): Redis => throw new Error('Class "Redis" not found'));
    }

    /**
     * @param Closure(Adapter): void $operation
     */
    private function runWhileDown(Closure $operation, Adapter $adapter, bool $read): void
    {
        if (!$read) {
            $operation($adapter);
            self::assertNotSame([], $this->logger->records, 'A dropped write must be logged.');

            return;
        }

        try {
            $operation($adapter);
            self::fail('A read must report that the storage is unavailable.');
        } catch (StorageException $exception) {
            self::assertNotInstanceOf(ErrorException::class, $exception->getPrevious());
        }
    }

    private function clock(): float
    {
        return $this->now;
    }

    private function connect(): Redis
    {
        $this->connections++;

        return Redis::fromExistingConnection(new FailingOnceDownRedis($this->server));
    }

    /**
     * @return array{name: string, help: string, type: string, labelNames: list<string>, labelValues: list<string>, value: int, command: int}
     */
    private static function counter(): array
    {
        return ['name' => 'c', 'help' => 'h', 'type' => 'counter', 'labelNames' => [], 'labelValues' => [], 'value' => 1, 'command' => Adapter::COMMAND_INCREMENT_INTEGER];
    }

    /**
     * @return array{name: string, help: string, type: string, labelNames: list<string>, labelValues: list<string>, value: int, command: int}
     */
    private static function gauge(): array
    {
        return ['name' => 'g', 'help' => 'h', 'type' => 'gauge', 'labelNames' => [], 'labelValues' => [], 'value' => 1, 'command' => Adapter::COMMAND_SET];
    }

    /**
     * @return array{name: string, help: string, type: string, labelNames: list<string>, labelValues: list<string>, value: float, maxAgeSeconds: int, quantiles: list<float>}
     */
    private static function summary(): array
    {
        return ['name' => 's', 'help' => 'h', 'type' => 'summary', 'labelNames' => [], 'labelValues' => [], 'value' => 0.1, 'maxAgeSeconds' => 60, 'quantiles' => [0.5]];
    }

    /**
     * @return array{name: string, help: string, type: string, labelNames: list<string>, labelValues: list<string>, value: float, buckets: list<float>}
     */
    private static function histogram(): array
    {
        return ['name' => 'h', 'help' => 'h', 'type' => 'histogram', 'labelNames' => [], 'labelValues' => [], 'value' => 0.1, 'buckets' => [0.5, 1.0]];
    }
}
