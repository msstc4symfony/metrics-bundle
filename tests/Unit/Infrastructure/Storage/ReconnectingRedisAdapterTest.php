<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Infrastructure\Storage;

use Closure;
use Error;
use Msstc4Symfony\MetricsBundle\Infrastructure\Storage\ReconnectingRedisAdapter;
use Msstc4Symfony\MetricsBundle\Test\Support\Redis\FailingOnceDownRedis;
use Msstc4Symfony\MetricsBundle\Test\Support\Redis\FakeRedisServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prometheus\Exception\StorageException;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\Redis;
use Prometheus\Storage\RedisClients\RedisClientException;
use RedisException;
use RuntimeException;
use Throwable;

final class ReconnectingRedisAdapterTest extends TestCase
{
    private FakeRedisServer $server;

    private int $connections = 0;

    protected function setUp(): void
    {
        if (version_compare((string) phpversion('redis'), '6.0.0', '<')) {
            self::markTestSkipped('FailingOnceDownRedis overrides the typed phpredis 6 signatures');
        }

        $this->server = new FakeRedisServer();
        $this->connections = 0;
    }

    /**
     * @param Closure(Adapter): void $operation
     */
    #[DataProvider('provideOperations')]
    public function testOperationReconnectsAfterRedisWentAway(Closure $operation, string $command): void
    {
        $adapter = new ReconnectingRedisAdapter($this->connect(...));
        $operation($adapter);

        $this->server->up = false;
        try {
            $operation($adapter);
            self::fail('The operation must fail while Redis is down.');
        } catch (RedisException) {
        }

        $this->server->up = true;
        $sentBeforeRecovery = \count($this->server->commands);
        $operation($adapter);

        self::assertSame($command, $this->server->commands[$sentBeforeRecovery] ?? null);
        self::assertSame(2, $this->connections);
    }

    /**
     * @return iterable<string, array{Closure(Adapter): void, string}>
     */
    public static function provideOperations(): iterable
    {
        yield 'counter' => [static fn (Adapter $adapter) => $adapter->updateCounter(self::counter()), 'EVAL'];
        yield 'gauge' => [static fn (Adapter $adapter) => $adapter->updateGauge(self::gauge()), 'EVAL'];
        yield 'histogram' => [static fn (Adapter $adapter) => $adapter->updateHistogram(self::histogram()), 'EVAL'];
        yield 'collect' => [static function (Adapter $adapter): void {
            $adapter->collect();
        }, 'SMEMBERS'];
        yield 'summary' => [static fn (Adapter $adapter) => $adapter->updateSummary(self::summary()), 'SETNX'];
        yield 'wipe storage' => [static fn (Adapter $adapter) => $adapter->wipeStorage(), 'EVAL'];
    }

    public function testEveryOperationWhileRedisIsDownTriesOneFreshConnection(): void
    {
        $adapter = new ReconnectingRedisAdapter($this->connect(...));
        $this->server->up = false;

        for ($i = 0; $i < 3; $i++) {
            try {
                $adapter->updateCounter(self::counter());
            } catch (RedisException) {
            }
        }

        self::assertSame(3, $this->connections);
    }

    public function testNonConnectionFailureKeepsTheConnection(): void
    {
        $adapter = new ReconnectingRedisAdapter($this->connect(...));

        try {
            $adapter->updateCounter(['labelNames' => ['l'], 'labelValues' => ["\xB1"]] + self::counter());
            self::fail('A label value that is not UTF-8 cannot be encoded.');
        } catch (RuntimeException) {
        }

        $adapter->updateCounter(self::counter());

        self::assertSame(1, $this->connections);
    }

    /**
     * @param Closure(): Throwable $error
     */
    #[DataProvider('provideConnectionErrors')]
    public function testClientLevelConnectionErrorAlsoReconnects(Closure $error): void
    {
        $adapter = new ReconnectingRedisAdapter($this->connect(...));
        $this->server->nextError = $error();

        try {
            $adapter->updateCounter(self::counter());
            self::fail('The injected error must propagate.');
        } catch (StorageException|RedisClientException) {
        }

        $adapter->updateCounter(self::counter());

        self::assertSame(2, $this->connections);
    }

    /**
     * @return iterable<string, array{Closure(): Throwable}>
     */
    public static function provideConnectionErrors(): iterable
    {
        yield 'storage exception' => [static fn (): Throwable => new StorageException("Can't connect to Redis server")];
        yield 'redis client exception' => [static fn (): Throwable => new RedisClientException('Connection lost')];
    }

    public function testConnectsEagerlySoTheFactoryCanFallBackOnConstructionErrors(): void
    {
        $this->expectException(Error::class);

        new ReconnectingRedisAdapter(static fn (): Redis => throw new Error('Class "Redis" not found'));
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
