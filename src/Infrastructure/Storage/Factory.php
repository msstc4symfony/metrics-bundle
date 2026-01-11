<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Storage;

use Prometheus\Storage\Adapter;
use Prometheus\Storage\APC;
use Prometheus\Storage\APCng;
use Prometheus\Storage\InMemory;
use Prometheus\Storage\Redis;
use Prometheus\Storage\RedisNg;
use Throwable;

class Factory implements FactoryInterface
{
    public function create(string $dsn): Adapter
    {
        $parts = parse_url($dsn);
        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);

            /** @var array<string, string> $query */
            $query = array_combine(
                array_map(strval(...), array_keys($query)),
                array_map(strval(...), array_values($query)),
            );
        }

        try {
            $adapter = match ($parts['scheme'] ?? null) {
                'redis' => $this->createRedis($parts, $query),
                'redisng' => $this->createRedisNg($parts, $query),
                'apc' => $this->createApc(),
                'apcng' => $this->createApcNg(),
                'inmemory' => $this->createInMemory(),
                default => $this->createInMemory(),
            };
        } catch (Throwable) {
            $adapter = null;
        }

        if ($adapter === null) {
            return $this->createInMemory();
        }

        return $adapter;
    }

    /**
     * @param array{scheme: 'redis', host?: string, port?: int<0, 65535>, user?: string, pass?: string, path?: string, query?: string, fragment?: string} $parts
     * @param array<string, string> $query
     */
    private function createRedis(array $parts, array $query): ?Redis
    {
        $options = [
            'port' => 6379,
            'read_timeout' => 10,
            'timeout' => 0.1,
            'user' => null,
            'password' => null,
            'ssl' => ['verify_peer' => false],
            'persistent_connections' => false,
            'database' => 0,
        ];

        if (!isset($parts['host'])) {
            return null;
        }

        $options['host'] = $parts['host'];
        $options['port'] = (int) ($parts['port'] ?? $options['port']);

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

        return new Redis($options);
    }

    /**
     * @param array{scheme: 'redisng', host?: string, port?: int<0, 65535>, user?: string, pass?: string, path?: string, query?: string, fragment?: string} $parts
     * @param array<string, string> $query
     */
    private function createRedisNg(array $parts, array $query): ?RedisNg
    {
        $options = [
            'port' => 6379,
            'read_timeout' => 10,
            'timeout' => 0.1,
            'user' => null,
            'password' => null,
            'ssl' => ['verify_peer' => false],
            'persistent_connections' => false,
            'database' => 0,
        ];

        if (!isset($parts['host'])) {
            return null;
        }

        $options['host'] = $parts['host'];
        $options['port'] = (int) ($parts['port'] ?? $options['port']);

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

        return new RedisNg($options);
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
