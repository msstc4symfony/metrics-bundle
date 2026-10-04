<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Infrastructure\Storage;

use ErrorException;
use Msstc4Symfony\MetricsBundle\Infrastructure\Storage\Factory;
use Msstc4Symfony\MetricsBundle\Infrastructure\Storage\ReconnectingRedisAdapter;
use Msstc4Symfony\MetricsBundle\Test\Support\CollectingLogger;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Prometheus\Exception\StorageException;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\Redis as PrometheusRedis;
use Prometheus\Storage\RedisNg;
use Psr\Log\NullLogger;
use Redis;
use RedisException;
use RuntimeException;

/**
 * Real phpredis against a throwaway RESP server that is killed and started again on the same port,
 * as when the Redis container restarts under a long-running worker.
 */
final class RedisReconnectTest extends TestCase
{
    private const string SERVER_SCRIPT = __DIR__ . '/../../../Support/Redis/resp-server.php';

    private int $port;

    private string $log;

    /** @var resource|null */
    private $server;

    #[Override]
    protected function setUp(): void
    {
        if (!\extension_loaded('redis') || !\function_exists('proc_open')) {
            self::markTestSkipped('ext-redis and proc_open required');
        }

        $this->port = $this->freePort();
        $log = tempnam(sys_get_temp_dir(), 'metrics-resp-');
        self::assertIsString($log);
        $this->log = $log;
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->stopServer();
        if (isset($this->log) && is_file($this->log)) {
            unlink($this->log);
        }
    }

    /**
     * @param non-empty-string $scheme
     * @param list<non-empty-string> $handshake
     */
    #[DataProvider('provideDsns')]
    public function testWritesRecoverAfterRedisRestart(string $scheme, string $dsnTail, array $handshake): void
    {
        if ($scheme === 'redisng' && !class_exists(RedisNg::class)) {
            self::markTestSkipped('promphp/prometheus_client_php without RedisNg');
        }

        $logger = new CollectingLogger();
        $adapter = new Factory($logger, 0.0)->create(\sprintf('%s://user:secret@127.0.0.1:%d%s', $scheme, $this->port, $dsnTail));
        $this->startServer();
        $adapter->updateCounter($this->counter());
        self::assertSame([...$handshake, 'EVAL'], $this->loggedCommands());

        $this->stopServer();
        $this->assertDroppedWhileDown($adapter, $logger);
        $this->assertDroppedWhileDown($adapter, $logger);

        $this->startServer();
        $adapter->updateCounter($this->counter());

        self::assertSame([...$handshake, 'EVAL'], $this->loggedCommands());
    }

    /**
     * @return iterable<string, array{non-empty-string, string, list<non-empty-string>}>
     */
    public static function provideDsns(): iterable
    {
        yield 'redis' => ['redis', '?database=5', ['AUTH user secret', 'SELECT 5']];
        yield 'redisng' => ['redisng', '?database=5', ['AUTH user secret', 'SELECT 5']];
        yield 'redis, database in path' => ['redis', '/3', ['AUTH user secret', 'SELECT 3']];
        yield 'redis, persistent connection' => ['redis', '?database=5&persistent_connections=1', ['AUTH user secret', 'SELECT 5']];
    }

    /**
     * @param non-empty-string $host
     */
    #[DataProvider('provideUnreachableHosts')]
    public function testUnreachableRedisNeverReachesTheApplicationErrorHandler(string $host): void
    {
        $logger = new CollectingLogger();
        $adapter = new Factory($logger, 0.0)->create(\sprintf('redis://%s:%d?database=5', $host, $this->port));

        $handled = 0;
        // Symfony's ErrorHandler throws PHP warnings as ErrorException (framework.php_errors.throw).
        set_error_handler(static function (int $type, string $message, string $file, int $line) use (&$handled): never {
            $handled++;

            throw new ErrorException($message, 0, $type, $file, $line);
        });

        try {
            $this->assertDroppedWhileDown($adapter, $logger);
            $this->assertDroppedWhileDown($adapter, $logger);
        } finally {
            restore_error_handler();
        }

        self::assertSame(0, $handled);
    }

    public function testHundredWritesAgainstAnUnresolvableHostStayFast(): void
    {
        $adapter = new Factory(new NullLogger(), ReconnectingRedisAdapter::DEFAULT_BACKOFF_SECONDS)->create('redis://metrics-unresolvable.invalid:6379?database=5');
        // The one lookup that opens the breaker depends on the runner's resolver; the rest must not repeat it.
        $adapter->updateCounter($this->counter());

        $started = hrtime(true);
        for ($i = 0; $i < 99; $i++) {
            $adapter->updateCounter($this->counter());
        }

        self::assertLessThan(0.5, (hrtime(true) - $started) / 1e9);
    }

    /**
     * Regression guard: the adapter must not share or reuse the application's connections (no
     * persistent ids, no global phpredis options, error handler restored).
     */
    public function testMetricsAdapterLeavesTheApplicationsOwnRedisClientsAlone(): void
    {
        $this->startServer();
        $idle = $this->plainClient();
        $adapter = new Factory(new CollectingLogger(), 0.0)->create('redis://127.0.0.1:' . $this->port . '?database=1');
        $adapter->updateCounter($this->counter());

        $this->stopServer();
        $adapter->updateCounter($this->counter());
        $this->startServer();
        $adapter->updateCounter($this->counter());

        self::assertTrue($idle->ping(), 'An application client idle during the outage reconnects by itself.');
    }

    /**
     * Characterisation of phpredis 6, with no metrics adapter in play: a client whose command hit the
     * outage stays failed after Redis is back. Applications must rebuild such clients themselves.
     */
    #[Group('characterisation')]
    public function testPhpredisClientThatHitTheOutageStaysFailedWithoutAnyMetricsAdapter(): void
    {
        $this->startServer();
        $client = $this->plainClient();

        $this->stopServer();
        try {
            $client->ping();
        } catch (RedisException) {
        }

        $this->startServer();

        $this->expectException(RedisException::class);
        $client->ping();
    }

    public function testHundredWritesAgainstAHangingRedisCostOneReadTimeout(): void
    {
        // Accepts connections (kernel backlog) but never answers. promphp sends SELECT before it applies
        // read_timeout, so without a cap the handshake waits for default_socket_timeout (60 s in prod).
        $hanging = stream_socket_server('tcp://127.0.0.1:' . $this->port);
        self::assertIsResource($hanging);
        $timeout = ini_set('default_socket_timeout', '5');
        $adapter = new Factory(new NullLogger(), ReconnectingRedisAdapter::DEFAULT_BACKOFF_SECONDS)->create('redis://127.0.0.1:' . $this->port . '?database=5&read_timeout=0.5');

        try {
            $started = hrtime(true);
            for ($i = 0; $i < 100; $i++) {
                $adapter->updateCounter($this->counter());
            }
            $elapsed = (hrtime(true) - $started) / 1e9;
            self::assertSame('5', ini_get('default_socket_timeout'), 'The adapter restores default_socket_timeout.');
        } finally {
            ini_set('default_socket_timeout', (string) $timeout);
            fclose($hanging);
        }

        self::assertLessThan(3.0, $elapsed);
    }

    public function testFirstWriteAfterTheBackoffReconnects(): void
    {
        $now = 0.0;
        $options = ['host' => '127.0.0.1', 'port' => $this->port, 'database' => 5, 'timeout' => 0.5, 'read_timeout' => 1.0, 'persistent_connections' => false];
        $adapter = new ReconnectingRedisAdapter(
            static fn (): PrometheusRedis => new PrometheusRedis($options),
            new CollectingLogger(),
            5.0,
            null,
            static function () use (&$now): float {
                return $now;
            },
        );
        $adapter->updateCounter($this->counter());

        $this->startServer();
        $now += 4.9;
        $adapter->updateCounter($this->counter());
        self::assertSame([], $this->loggedCommands(), 'Inside the backoff window: no connection attempt.');

        $now += 0.1;
        $adapter->updateCounter($this->counter());
        self::assertSame(['SELECT 5', 'EVAL'], $this->loggedCommands());
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function provideUnreachableHosts(): iterable
    {
        yield 'unresolvable host' => ['metrics-unresolvable.invalid'];
        yield 'closed port' => ['127.0.0.1'];
    }

    private function assertDroppedWhileDown(Adapter $adapter, CollectingLogger $logger): void
    {
        $adapter->updateCounter($this->counter());
        self::assertNotSame([], $logger->records, 'A write while Redis is down is dropped and logged.');

        try {
            $adapter->collect();
            self::fail('A read must report that the storage is unavailable.');
        } catch (StorageException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @return list<string>
     */
    private function loggedCommands(): array
    {
        $lines = file($this->log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);

        return $lines;
    }

    private function plainClient(): Redis
    {
        $client = new Redis();
        self::assertTrue($client->connect('127.0.0.1', $this->port, 0.5));

        return $client;
    }

    private function startServer(): void
    {
        $server = proc_open(
            [\PHP_BINARY, self::SERVER_SCRIPT, (string) $this->port, $this->log],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        self::assertIsResource($server);
        $this->server = $server;

        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $probe = @stream_socket_client('tcp://127.0.0.1:' . $this->port, $errorCode, $errorMessage, 0.1);
            if ($probe !== false) {
                fclose($probe);
                file_put_contents($this->log, '');

                return;
            }

            usleep(20_000);
        }

        throw new RuntimeException('RESP test server did not start on port ' . $this->port);
    }

    private function stopServer(): void
    {
        if ($this->server === null) {
            return;
        }

        proc_terminate($this->server, 9);
        proc_close($this->server);
        $this->server = null;
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        self::assertIsString($name);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /**
     * @return array{name: string, help: string, type: string, labelNames: list<string>, labelValues: list<string>, value: int, command: int}
     */
    private function counter(): array
    {
        return ['name' => 'c', 'help' => 'h', 'type' => 'counter', 'labelNames' => [], 'labelValues' => [], 'value' => 1, 'command' => Adapter::COMMAND_INCREMENT_INTEGER];
    }
}
