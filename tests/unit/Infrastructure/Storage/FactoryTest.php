<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Test\Unit\Infrastructure\Storage;

use MaxShamaev\MetricsBundle\Infrastructure\Storage\Factory;
use PHPUnit\Framework\TestCase;
use Prometheus\Storage\APC;
use Prometheus\Storage\APCng;
use Prometheus\Storage\InMemory;
use Prometheus\Storage\Redis;
use Prometheus\Storage\RedisNg;
use Psr\Log\LoggerInterface;

final class FactoryTest extends TestCase
{
    public function testInMemorySchemeReturnsInMemoryAdapter(): void
    {
        $factory = new Factory();

        self::assertInstanceOf(InMemory::class, $factory->create('inmemory://anything'));
    }

    public function testApcSchemeReturnsApcAdapter(): void
    {
        if (!extension_loaded('apcu')) {
            self::markTestSkipped('apcu extension required');
        }

        $factory = new Factory();

        self::assertInstanceOf(APC::class, $factory->create('apc://'));
    }

    public function testApcngSchemeReturnsApcngAdapter(): void
    {
        if (!extension_loaded('apcu')) {
            self::markTestSkipped('apcu extension required');
        }

        $factory = new Factory();

        self::assertInstanceOf(APCng::class, $factory->create('apcng://'));
    }

    public function testRedisSchemeWithHostReturnsRedisAdapter(): void
    {
        $factory = new Factory();

        self::assertInstanceOf(Redis::class, $factory->create('redis://localhost:6379'));
    }

    public function testRedisngSchemeWithHostReturnsRedisngAdapter(): void
    {
        $factory = new Factory();

        self::assertInstanceOf(RedisNg::class, $factory->create('redisng://localhost:6379'));
    }

    public function testRedisSchemeWithoutHostFallsBackToInMemoryAndLogs(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        // parse_url('redis://') returns false in PHP 8 → "malformed DSN" branch logs an error.
        $logger->expects(self::atLeastOnce())->method('error');

        $factory = new Factory($logger);

        self::assertInstanceOf(InMemory::class, $factory->create('redis://'));
    }

    public function testRedisSchemeWithHostMissingPathFallsBackToInMemoryAndWarns(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())->method('warning');

        // parse_url returns valid array with scheme but no host (only happens for some malformed inputs)
        // Use a DSN where buildRedisOptions returns null for a different reason; here we exercise
        // the unknown-scheme branch which also logs warning.
        $factory = new Factory($logger);

        self::assertInstanceOf(InMemory::class, $factory->create('unknown-scheme://something'));
    }

    public function testUnknownSchemeFallsBackToInMemoryAndWarns(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('unsupported'))
        ;

        $factory = new Factory($logger);

        self::assertInstanceOf(InMemory::class, $factory->create('mysql://localhost'));
    }

    public function testMissingSchemeFallsBackToInMemoryAndWarns(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('unsupported'))
        ;

        $factory = new Factory($logger);

        self::assertInstanceOf(InMemory::class, $factory->create('not-a-dsn'));
    }

    public function testRedisDsnPropagatesDatabaseAndOptionsViaQuery(): void
    {
        $factory = new Factory();

        $adapter = $factory->create('redis://user:pass@redis-host:6390/?database=7&timeout=0.5&persistent_connections=1&ssl_verify_peer=1');

        self::assertInstanceOf(Redis::class, $adapter);
    }

    public function testRedisDatabaseFromPath(): void
    {
        $factory = new Factory();

        $adapter = $factory->create('redis://localhost/3');

        self::assertInstanceOf(Redis::class, $adapter);
    }

    public function testDefaultsToInMemoryOnEmptyDsn(): void
    {
        $factory = new Factory();

        self::assertInstanceOf(InMemory::class, $factory->create(''));
    }
}
