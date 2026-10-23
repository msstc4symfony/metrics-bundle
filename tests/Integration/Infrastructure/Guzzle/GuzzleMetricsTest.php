<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Infrastructure\Guzzle;

use GuzzleHttp\ClientInterface;
use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\TestKernel;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;
use Override;
use Prometheus\RegistryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class GuzzleMetricsTest extends KernelTestCase
{
    private const DSN_ENV = 'METRICS_STORAGE_DSN';

    #[Override]
    protected function setUp(): void
    {
        if (!interface_exists(ClientInterface::class)) {
            self::markTestSkipped('guzzlehttp/guzzle not installed');
        }

        (new Filesystem())->remove(TestKernel::cacheRoot());
        $_SERVER[self::DSN_ENV] = 'inmemory://';
    }

    #[Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        unset($_SERVER[self::DSN_ENV]);
        (new Filesystem())->remove(TestKernel::cacheRoot());
    }

    public function testContainerClientsWriteTheHttpConnectionSeries(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $configured = $container->get(TestKernel::GUZZLE_CLIENT);
        $built = $container->get(TestKernel::GUZZLE_FACTORY_CLIENT);
        self::assertInstanceOf(ClientInterface::class, $configured);
        self::assertInstanceOf(ClientInterface::class, $built);

        $configured->request('GET', 'https://example.com/a/42');
        $built->request('POST', 'https://api.example.com/jobs');

        $registry = $container->get(RegistryInterface::class);
        self::assertInstanceOf(RegistryInterface::class, $registry);

        self::assertEqualsCanonicalizing(
            [
                [['unknown', 'unknown', 'GET', 'example.com', '/a/:id', '200'], '1'],
                [['unknown', 'unknown', 'POST', 'api.example.com', '/jobs', '503'], '1'],
            ],
            RegistrySamples::samples($registry, BuiltInMetric::HTTP_CONNECTION_RESPONSE),
        );
        self::assertCount(2, RegistrySamples::samples($registry, BuiltInMetric::HTTP_CONNECTION_REQUEST));
        self::assertCount(2, RegistrySamples::samples($registry, BuiltInMetric::HTTP_CONNECTION_DURATION_HISTOGRAM_SECONDS, '_count'));
    }

    #[Override]
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }
}
