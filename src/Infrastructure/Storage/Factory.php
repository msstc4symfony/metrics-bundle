<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Storage;

use Monolog\Attribute\WithMonologChannel;
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

// Its own channel keeps the storage logger out of the metrics HandlerDecorator:
// decorating it would make the adapter depend on ErrorCollector, which needs the adapter.
#[WithMonologChannel(self::LOG_CHANNEL)]
final readonly class Factory implements FactoryInterface
{
    public const string LOG_CHANNEL = 'metrics_bundle';

    private const string DEFAULT_REDIS_HOST = '127.0.0.1';

    private const int DEFAULT_REDIS_PORT = 6379;

    private const int DEFAULT_REDIS_DATABASE = 0;

    private const int DEFAULT_REDIS_READ_TIMEOUT = 1;

    private const float DEFAULT_REDIS_TIMEOUT = 0.1;

    private const bool DEFAULT_REDIS_VERIFY_PEER = true;

    private const bool DEFAULT_REDIS_PERSISTENT_CONNECTIONS = false;

    /**
     * parse_url() rejects "scheme://" with nothing after it, yet that is the natural
     * DSN for storages that have no host.
     *
     * @var list<non-empty-string>
     */
    private const array HOSTLESS_SCHEMES = ['apc', 'apcng', 'inmemory'];

    public function __construct(
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    #[Override]
    public function create(string $dsn): Adapter
    {
        $parts = $this->parseDsn($dsn);
        if ($parts === false) {
            $this->logger->error('metrics-bundle: malformed METRICS_STORAGE_DSN, falling back to InMemory storage', [
                'dsn_scheme' => 'invalid',
            ]);

            return $this->createInMemory();
        }

        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $raw);

            foreach ($raw as $key => $value) {
                // Nested keys such as "a[]=1" produce arrays; no supported option takes one.
                if (is_string($value)) {
                    $query[(string) $key] = $value;
                }
            }
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
     * @return array{
     *     scheme?: string,
     *     host?: string,
     *     port?: int<0, 65535>,
     *     user?: string,
     *     pass?: string,
     *     path?: string,
     *     query?: string,
     *     fragment?: string,
     * }|false
     */
    private function parseDsn(string $dsn): array|false
    {
        $parts = parse_url($dsn);
        if ($parts !== false) {
            return $parts;
        }

        $schemes = implode('|', self::HOSTLESS_SCHEMES);
        if (preg_match('#^(' . $schemes . ')://(?:/)?(?:\?(.*))?$#', $dsn, $match) !== 1) {
            return false;
        }

        return isset($match[2]) && $match[2] !== ''
            ? ['scheme' => $match[1], 'query' => $match[2]]
            : ['scheme' => $match[1]];
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
    private function createRedis(array $parts, array $query): ReconnectingRedisAdapter
    {
        $options = $this->buildRedisOptions($parts, $query);

        return new ReconnectingRedisAdapter(static fn (): Redis => new Redis($options), $this->logger);
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
    private function createRedisNg(array $parts, array $query): ReconnectingRedisAdapter
    {
        $options = $this->buildRedisOptions($parts, $query);

        return new ReconnectingRedisAdapter(static fn (): RedisNg => new RedisNg($options), $this->logger);
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

        $path = ltrim($parts['path'] ?? '', '/');
        if (preg_match('/^\d+\z/', $path) === 1) {
            $options['database'] = (int) $path;
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
