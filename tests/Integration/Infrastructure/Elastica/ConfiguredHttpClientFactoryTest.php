<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Infrastructure\Elastica;

use Msstc4Symfony\MetricsBundle\Infrastructure\Elastica\ConfiguredHttpClientFactory;
use Override;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Throwable;

final class ConfiguredHttpClientFactoryTest extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        if (!class_exists('Elastic\Elasticsearch\Transport\Adapter\AdapterOptions') || !class_exists(Psr18Client::class)) {
            self::markTestSkipped('ruflin/elastica 8 or symfony/http-client not installed');
        }
    }

    public function testPassesTheClientThroughWithoutOptions(): void
    {
        $client = self::createStub(ClientInterface::class);

        self::assertSame($client, ConfiguredHttpClientFactory::create($client, null, null));
        self::assertSame($client, ConfiguredHttpClientFactory::create($client, [], []));
    }

    public function testDiscoversAClientWhenNoneIsConfigured(): void
    {
        self::assertInstanceOf(ClientInterface::class, ConfiguredHttpClientFactory::create(null, null, null));
    }

    public function testAppliesOptionsThroughTheElasticsearchAdapter(): void
    {
        $client = new Psr18Client(new MockHttpClient());

        $configured = ConfiguredHttpClientFactory::create($client, ['ssl_verify' => false], ['timeout' => 3]);

        self::assertInstanceOf(Psr18Client::class, $configured);
        self::assertNotSame($client, $configured);
    }

    public function testRejectsOptionsForAClientWithoutAnAdapterLikeElastica(): void
    {
        $client = self::createStub(ClientInterface::class);

        try {
            ConfiguredHttpClientFactory::create($client, null, ['timeout' => 3]);
            self::fail('Expected the Elasticsearch HTTP client exception');
        } catch (Throwable $exception) {
            self::assertSame('Elastic\Elasticsearch\Exception\HttpClientException', $exception::class);
            self::assertStringContainsString('is not supported for custom options', $exception->getMessage());
        }
    }
}
