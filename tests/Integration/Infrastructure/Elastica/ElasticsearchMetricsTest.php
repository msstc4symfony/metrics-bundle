<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Infrastructure\Elastica;

use Elastica\Client;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\ElasticsearchKernel;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\TestKernel;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;
use Override;
use PHPUnit\Framework\Attributes\Group;
use Prometheus\RegistryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The full chain against a live cluster: bundle wiring, a subclassed client, a real request, the recorded sample.
 */
#[Group('elasticsearch')]
final class ElasticsearchMetricsTest extends KernelTestCase
{
    #[Override]
    protected function setUp(): void
    {
        if (ElasticsearchKernel::url() === null) {
            self::markTestSkipped(ElasticsearchKernel::URL_ENV . ' is not set');
        }

        if (!class_exists(Client::class)) {
            self::markTestSkipped('ruflin/elastica not installed');
        }

        new Filesystem()->remove(TestKernel::cacheRoot());
    }

    #[Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        new Filesystem()->remove(TestKernel::cacheRoot());
    }

    #[Override]
    protected static function getKernelClass(): string
    {
        return ElasticsearchKernel::class;
    }

    public function testARealRequestIsRecorded(): void
    {
        self::bootKernel();

        $client = self::getContainer()->get(ElasticsearchKernel::CLIENT);
        self::assertInstanceOf(Client::class, $client);
        // getCluster() loads the cluster state first, so two requests are recorded.
        $client->getCluster()->getHealth()->getStatus();

        $registry = self::getContainer()->get(RegistryInterface::class);
        self::assertInstanceOf(RegistryInterface::class, $registry);
        $labels = RegistrySamples::labels($registry, MetricLabelEnum::ELASTICA_REQUEST_SUCCESS);
        sort($labels);
        self::assertSame(
            [['app', 'cmp', 'GET', '_cluster/health'], ['app', 'cmp', 'GET', '_cluster/state']],
            $labels,
        );
        self::assertTrue(RegistrySamples::exists($registry, MetricLabelEnum::ELASTICA_REQUEST_DURATION_HISTOGRAM_SECONDS));
        self::assertFalse(RegistrySamples::exists($registry, MetricLabelEnum::ELASTICA_REQUEST_FAILED));
    }
}
