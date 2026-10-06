<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Infrastructure\Elastica;

use Elastica\Client;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\ElasticaWiringKernel;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\TestKernel;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prometheus\RegistryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * msstc4symfony_metrics.elastica.sanitize_path reaches the measurement point of the installed Elastica version.
 */
final class ElasticaPathLabelWiringTest extends TestCase
{
    private ?ElasticaWiringKernel $kernel = null;

    #[Override]
    protected function setUp(): void
    {
        if (!class_exists(Client::class)) {
            self::markTestSkipped('ruflin/elastica not installed');
        }

        new Filesystem()->remove(TestKernel::cacheRoot());
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        new Filesystem()->remove(TestKernel::cacheRoot());
    }

    /**
     * @return iterable<string, array{bool|null, string}>
     */
    public static function configProvider(): iterable
    {
        yield 'default' => [null, 'products/_doc/:id'];
        yield 'enabled' => [true, 'products/_doc/:id'];
        yield 'disabled' => [false, 'products/_doc/sku%2F1'];
    }

    #[DataProvider('configProvider')]
    public function testDocumentPathLabelFollowsTheOption(?bool $sanitizePath, string $expected): void
    {
        $this->kernel = new ElasticaWiringKernel($sanitizePath);
        $this->kernel->boot();

        $container = $this->kernel->getContainer();

        $client = $container->get(ElasticaWiringKernel::CLIENT);
        self::assertInstanceOf(Client::class, $client);
        $client->getIndex('products')->getDocument('sku/1');

        $testContainer = $container->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $testContainer);
        $registry = $testContainer->get(RegistryInterface::class);
        self::assertInstanceOf(RegistryInterface::class, $registry);
        self::assertSame([['app', 'cmp', 'GET', $expected]], RegistrySamples::labels($registry, MetricLabelEnum::ELASTICA_REQUEST_SUCCESS));
    }
}
