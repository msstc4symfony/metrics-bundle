<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Infrastructure\Storage;

use ErrorException;
use Msstc4Symfony\MetricsBundle\Infrastructure\Storage\Factory;
use Msstc4Symfony\MetricsBundle\Test\Support\CollectingLogger;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prometheus\Exception\StorageException;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\RedisNg;
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
        $adapter = new Factory($logger)->create(\sprintf('%s://user:secret@127.0.0.1:%d%s', $scheme, $this->port, $dsnTail));
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
        $adapter = new Factory($logger)->create(\sprintf('redis://%s:%d?database=5', $host, $this->port));

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
