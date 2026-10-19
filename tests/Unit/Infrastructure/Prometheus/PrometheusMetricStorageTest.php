<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Infrastructure\Prometheus;

use Msstc4Symfony\MetricsBundle\Application\Port\MetricExport;
use Msstc4Symfony\MetricsBundle\Application\Port\MetricStorageException;
use Msstc4Symfony\MetricsBundle\Infrastructure\Prometheus\PrometheusMetricStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Exception\StorageException;
use Prometheus\RegistryInterface;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\InMemory;

#[CoversClass(PrometheusMetricStorage::class)]
#[UsesClass(MetricExport::class)]
final class PrometheusMetricStorageTest extends TestCase
{
    public function testExportsTextWithItsContentType(): void
    {
        $storage = new InMemory();
        $registry = new CollectorRegistry($storage);
        $registry->getOrRegisterCounter('symfony', 'probe', 'probe', [])->inc();

        $export = (new PrometheusMetricStorage($registry, new RenderTextFormat(), $storage))->export();

        self::assertSame(RenderTextFormat::MIME_TYPE, $export->contentType);
        self::assertStringContainsString('symfony_probe 1', $export->body);
    }

    public function testClearWipesTheStorage(): void
    {
        $storage = new InMemory();
        $registry = new CollectorRegistry($storage);
        $registry->getOrRegisterCounter('symfony', 'probe', 'probe', [])->inc();

        (new PrometheusMetricStorage($registry, new RenderTextFormat(), $storage))->clear();

        self::assertSame([], $registry->getMetricFamilySamples());
    }

    public function testWrapsStorageFailures(): void
    {
        $previous = new StorageException('down');
        $registry = self::createStub(RegistryInterface::class);
        $registry->method('getMetricFamilySamples')->willThrowException($previous);
        $adapter = self::createStub(Adapter::class);
        $adapter->method('wipeStorage')->willThrowException($previous);
        $storage = new PrometheusMetricStorage($registry, new RenderTextFormat(), $adapter);

        foreach ([$storage->export(...), $storage->clear(...)] as $call) {
            try {
                $call();
                self::fail('MetricStorageException expected');
            } catch (MetricStorageException $e) {
                self::assertSame($previous, $e->getPrevious());
                self::assertSame('down', $e->getMessage());
            }
        }
    }
}
