<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Prometheus;

use Msstc4Symfony\MetricsBundle\Application\Port\MetricExport;
use Msstc4Symfony\MetricsBundle\Application\Port\MetricStorageException;
use Msstc4Symfony\MetricsBundle\Application\Port\MetricStorageInterface;
use Override;
use Prometheus\RegistryInterface;
use Prometheus\RendererInterface;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\Adapter;
use Throwable;

/**
 * @internal
 */
final readonly class PrometheusMetricStorage implements MetricStorageInterface
{
    public function __construct(
        private RegistryInterface $registry,
        private RendererInterface $renderer,
        private Adapter $storage,
    ) {
    }

    #[Override]
    public function export(): MetricExport
    {
        try {
            return new MetricExport($this->renderer->render($this->registry->getMetricFamilySamples()), RenderTextFormat::MIME_TYPE);
        } catch (Throwable $exception) {
            throw new MetricStorageException($exception->getMessage(), 0, $exception);
        }
    }

    #[Override]
    public function clear(): void
    {
        try {
            $this->storage->wipeStorage();
        } catch (Throwable $exception) {
            throw new MetricStorageException($exception->getMessage(), 0, $exception);
        }
    }
}
