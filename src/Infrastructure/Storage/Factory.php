<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Storage;

use Override;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\APC;
use Prometheus\Storage\APCng;
use Prometheus\Storage\InMemory;
use Prometheus\Storage\Redis;
use Prometheus\Storage\RedisNg;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

final readonly class Factory implements FactoryInterface
{
    private const string DEFAULT_REDIS_HOST = '127.0.0.1';

    private const int DEFAULT_REDIS_PORT = 6379;

    private const int DEFAULT_REDIS_DATABASE = 0;

    private const int DEFAULT_REDIS_READ_TIMEOUT = 1;

    private const float DEFAULT_REDIS_TIMEOUT = 0.1;

    private const bool DEFAULT_REDIS_VERIFY_PEER = true;

    private const bool DEFAULT_REDIS_PERSISTENT_CONNECTIONS = false;

    public function __construct(
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    #[Override]
    public function create(string $dsn): Adapter
    {
        $parts = parse_url($dsn);
        if ($parts === false) {
            $this->logger->error('metrics-bundle: malformed METRICS_STORAGE_DSN, falling back to InMemory storage', [
                'dsn_scheme' => 'invalid',
            ]);

            return $this->createInMemory();
        }

        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);

            /** @var array<string, string> $query */
            $query = array_combine(
                array_map(strval(...), array_keys($query)),
                array_map(strval(...), array_values($query)),
            );
        }

        $scheme = $parts['scheme'] ?? null;

        try {
            $adapter = match ($scheme) {
                'redis' => $this->createRedis($parts, $query),
                'redisng' => $this->createRedisNg($parts, $query),
                'apc' => $this->createApc(),
                'apcng' => $this->createApcNg(),
                'inmemory' => $this->createInMemory(),
                default => null,
            };
        } catch (Throwable $exception) {
            $this->logger->error(
                'metrics-bundle: storage adapter construction failed, falling back to InMemory',
                ['dsn_scheme' => $scheme, 'exception' => $exception],
            );

            return $this->createInMemory();
        }

        if ($adapter === null) {
            $this->logger->warning(
                'metrics-bundle: unsupported or missing DSN scheme, falling back to InMemory',
                ['dsn_scheme' => $scheme],
            );

            return $this->createInMemory();
        }

        return $adapter;
    }

    /**
     * @param array{
     *     scheme?: string,
     *     host?: string,
     *     port?: int<0, 65535>,
     *     user?: string,
     *     pass?: string,
     *     path?: string,
     *     query?: string,
     *     fragment?: string,
     * } $parts
     * @param array<string, string> $query
     */
    private function createRedis(array $parts, array $query): Redis
    {
        return new Redis($this->buildRedisOptions($parts, $query));
    }

    /**
     * @param array{
     *     scheme?: string,
     *     host?: string,
     *     port?: int<0, 65535>,
     *     user?: string,
     *     pass?: string,
     *     path?: string,
     *     query?: string,
     *     fragment?: string,
     * } $parts
     * @param array<string, string> $query
     */
    private function createRedisNg(array $parts, array $query): RedisNg
    {
        return new RedisNg($this->buildRedisOptions($parts, $query));
    }

    /**
     * @param array{
     *     host?: string,
     *     port?: int<0, 65535>,
     *     user?: string,
     *     pass?: string,
     *     path?: string,
     *     query?: string,
     *     fragment?: string,
     * } $parts
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function buildRedisOptions(array $parts, array $query): array
    {
        $options = [
            'host' => $parts['host'] ?? self::DEFAULT_REDIS_HOST,
            'port' => (int) ($parts['port'] ?? self::DEFAULT_REDIS_PORT),
            'read_timeout' => self::DEFAULT_REDIS_READ_TIMEOUT,
            'timeout' => self::DEFAULT_REDIS_TIMEOUT,
            'user' => null,
            'password' => null,
            'ssl' => ['verify_peer' => self::DEFAULT_REDIS_VERIFY_PEER],
            'persistent_connections' => self::DEFAULT_REDIS_PERSISTENT_CONNECTIONS,
            'database' => self::DEFAULT_REDIS_DATABASE,
        ];

        if (isset($parts['user'])) {
            $options['user'] = $parts['user'];
        }

        if (isset($parts['pass'])) {
            $options['password'] = $parts['pass'];
        }

        if (isset($parts['path']) && is_numeric($parts['path'])) {
            $options['database'] = (int) $parts['path'];
        }

        if (isset($query['read_timeout'])) {
            $options['read_timeout'] = (float) $query['read_timeout'];
        }

        if (isset($query['timeout'])) {
            $options['timeout'] = (float) $query['timeout'];
        }

        if (isset($query['database'])) {
            $options['database'] = (int) $query['database'];
        }

        if (isset($query['persistent_connections'])) {
            $options['persistent_connections'] = (bool) $query['persistent_connections'];
        }

        if (isset($query['ssl_verify_peer'])) {
            $options['ssl'] = ['verify_peer' => (bool) $query['ssl_verify_peer']];
        }

        return $options;
    }

    private function createApc(): APC
    {
        return new APC();
    }

    private function createApcNg(): APCng
    {
        return new APCng();
    }

    private function createInMemory(): InMemory
    {
        return new InMemory();
    }
}
