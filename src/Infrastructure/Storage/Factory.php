<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Storage;

use Prometheus\Storage\Adapter;
use Prometheus\Storage\APC;
use Prometheus\Storage\APCng;
use Prometheus\Storage\InMemory;
use Prometheus\Storage\Redis;
use Prometheus\Storage\RedisNg;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

class Factory implements FactoryInterface
{
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

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
    private function createRedis(array $parts, array $query): ?Redis
    {
        $options = $this->buildRedisOptions($parts, $query);

        return $options === null ? null : new Redis($options);
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
    private function createRedisNg(array $parts, array $query): ?RedisNg
    {
        $options = $this->buildRedisOptions($parts, $query);

        return $options === null ? null : new RedisNg($options);
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
     * @return array<string, mixed>|null
     */
    private function buildRedisOptions(array $parts, array $query): ?array
    {
        if (!isset($parts['host'])) {
            $this->logger->warning('metrics-bundle: Redis DSN missing host, falling back to InMemory');

            return null;
        }

        $options = [
            'host' => $parts['host'],
            'port' => (int) ($parts['port'] ?? 6379),
            'read_timeout' => 1,
            'timeout' => 0.1,
            'user' => null,
            'password' => null,
            'ssl' => ['verify_peer' => true],
            'persistent_connections' => false,
            'database' => 0,
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
