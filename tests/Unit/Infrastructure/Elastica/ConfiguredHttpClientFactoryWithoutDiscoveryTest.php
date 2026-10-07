<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Infrastructure\Elastica;

use Http\Discovery\ClassDiscovery;
use Http\Discovery\Exception\NotFoundException;
use Http\Discovery\Psr18ClientDiscovery;
use Msstc4Symfony\MetricsBundle\Infrastructure\Elastica\ConfiguredHttpClientFactory;
use Override;
use PHPUnit\Framework\TestCase;

final class ConfiguredHttpClientFactoryWithoutDiscoveryTest extends TestCase
{
    private const string CURL_CLIENT = 'Elastic\Transport\Client\Curl';

    /**
     * @var list<string> discovery strategy class names, typed as plain strings by php-http/discovery
     */
    private array $strategies = [];

    #[Override]
    protected function setUp(): void
    {
        if (!class_exists(Psr18ClientDiscovery::class)) {
            self::markTestSkipped('php-http/discovery not installed');
        }

        foreach (ClassDiscovery::getStrategies() as $strategy) {
            $this->strategies[] = $strategy;
        }

        ClassDiscovery::setStrategies([]);
    }

    #[Override]
    protected function tearDown(): void
    {
        if (class_exists(Psr18ClientDiscovery::class)) {
            ClassDiscovery::setStrategies($this->strategies);
        }
    }

    public function testFallsBackToTheElasticTransportCurlClientLikeElasticTransport(): void
    {
        if (!class_exists(self::CURL_CLIENT)) {
            self::markTestSkipped('elastic/transport without the Curl client (Elastica 7 and 8)');
        }

        self::assertSame(self::CURL_CLIENT, ConfiguredHttpClientFactory::create(null, null, null)::class);
    }

    public function testAppliesOptionsToTheCurlFallbackThroughTheElasticsearchAdapter(): void
    {
        if (!class_exists(self::CURL_CLIENT) || !class_exists('Elastic\Elasticsearch\Transport\Adapter\AdapterOptions')) {
            self::markTestSkipped('elastic/transport without the Curl client (Elastica 7 and 8)');
        }

        self::assertSame(self::CURL_CLIENT, ConfiguredHttpClientFactory::create(null, ['ssl_verify' => false], null)::class);
    }

    public function testRethrowsTheDiscoveryFailureWhenElasticTransportHasNoFallback(): void
    {
        if (class_exists(self::CURL_CLIENT)) {
            self::markTestSkipped('elastic/transport ships the Curl fallback client');
        }

        $this->expectException(NotFoundException::class);

        ConfiguredHttpClientFactory::create(null, null, null);
    }
}
