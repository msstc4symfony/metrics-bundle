<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Support\Redis;

use Override;
use Redis;
use RedisException;
use Throwable;

/**
 * Mimics phpredis 6: once a command loses the connection, the client is marked failed and every
 * later command throws "went away" until connect() is called again, even after the server is back.
 */
final class FailingOnceDownRedis extends Redis
{
    private bool $failed = false;

    public function __construct(
        private readonly FakeRedisServer $server,
    ) {
        parent::__construct();
    }

    #[Override]
    public function isConnected(): bool
    {
        return true;
    }

    #[Override]
    public function getOption(int $option): mixed
    {
        return null;
    }

    /**
     * @param array<array-key, mixed> $args must stay as wide as Redis::eval() accepts
     */
    #[Override]
    public function eval(string $script, array $args = [], int $num_keys = 0): mixed
    {
        $this->send('EVAL');

        return 1;
    }

    #[Override]
    public function setnx(string $key, mixed $value): bool
    {
        $this->send('SETNX');

        return true;
    }

    /**
     * @param array<array-key, mixed>|int|null $options must stay as wide as Redis::set() accepts
     */
    #[Override]
    public function set(string $key, mixed $value, mixed $options = null): bool
    {
        $this->send('SET');

        return true;
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function sMembers(string $key): array
    {
        $this->send('SMEMBERS');

        return [];
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function keys(string $pattern): array
    {
        $this->send('KEYS');

        return [];
    }

    /**
     * @throws RedisException
     */
    private function send(string $command): void
    {
        if ($this->server->nextError instanceof Throwable) {
            $error = $this->server->nextError;
            $this->server->nextError = null;

            throw $error;
        }

        if ($this->failed) {
            throw new RedisException('Redis server fake:6379 went away');
        }

        if (!$this->server->up) {
            $this->failed = true;

            throw new RedisException('Connection lost');
        }

        $this->server->commands[] = $command;
    }
}
