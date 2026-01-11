<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Prometheus;

use Prometheus\Counter;
use Prometheus\Exception\MetricNotFoundException;
use Prometheus\Exception\MetricsRegistrationException;
use Prometheus\Gauge;
use Prometheus\Histogram;
use Prometheus\MetricFamilySamples;
use Prometheus\RegistryInterface;
use Prometheus\Storage\Adapter;
use Prometheus\Summary;

class CollectorRegistry implements RegistryInterface
{
    /**
     * @var Gauge[]
     */
    private array $gauges = [];

    /**
     * @var Counter[]
     */
    private array $counters = [];

    /**
     * @var Histogram[]
     */
    private array $histograms = [];

    /**
     * @var Summary[]
     */
    private array $summaries = [];

    public function __construct(
        private readonly Adapter $storageAdapter,
    ) {
    }

    /**
     * Removes all previously stored metrics from underlying storage adapter.
     */
    public function wipeStorage(): void
    {
        $this->storageAdapter->wipeStorage();
    }

    /**
     * @return MetricFamilySamples[]
     */
    public function getMetricFamilySamples(bool $sortMetrics = true): array
    {
        return $this->storageAdapter->collect($sortMetrics);  /** @phpstan-ignore-line */
    }

    /**
     * @param string[] $labels e.g. ['controller', 'action']
     *
     * @throws MetricsRegistrationException
     */
    public function registerGauge(string $namespace, string $name, string $help, array $labels = []): Gauge
    {
        $metricIdentifier = $this->metricIdentifier($namespace, $name);
        if (isset($this->gauges[$metricIdentifier])) {
            throw new MetricsRegistrationException(sprintf('Metric ` . %s . ` already registered', $metricIdentifier));
        }
        $this->gauges[$metricIdentifier] = new Gauge(
            $this->storageAdapter,
            $namespace,
            $name,
            $help,
            $labels,
        );

        return $this->gauges[$metricIdentifier];
    }

    /**
     * @throws MetricNotFoundException
     */
    public function getGauge(string $namespace, string $name): Gauge
    {
        $metricIdentifier = $this->metricIdentifier($namespace, $name);
        if (!isset($this->gauges[$metricIdentifier])) {
            throw new MetricNotFoundException('Metric not found:' . $metricIdentifier);
        }

        return $this->gauges[$metricIdentifier];
    }

    /**
     * @param string $namespace e.g. cms
     * @param string $name e.g. duration_seconds
     * @param string $help e.g. The duration something took in seconds.
     * @param string[] $labels e.g. ['controller', 'action']
     *
     * @throws MetricsRegistrationException
     */
    public function getOrRegisterGauge(string $namespace, string $name, string $help, array $labels = []): Gauge
    {
        try {
            $gauge = $this->getGauge($namespace, $name);
        } catch (MetricNotFoundException) {
            $gauge = $this->registerGauge($namespace, $name, $help, $labels);
        }

        return $gauge;
    }

    /**
     * @param string $namespace e.g. cms
     * @param string $name e.g. requests
     * @param string $help e.g. The number of requests made.
     * @param string[] $labels e.g. ['controller', 'action']
     *
     * @throws MetricsRegistrationException
     */
    public function registerCounter(string $namespace, string $name, string $help, array $labels = []): Counter
    {
        $metricIdentifier = $this->metricIdentifier($namespace, $name);
        if (isset($this->counters[$metricIdentifier])) {
            throw new MetricsRegistrationException(sprintf('Metric ` . %s . ` already registered', $metricIdentifier));
        }
        $this->counters[$metricIdentifier] = new Counter(
            $this->storageAdapter,
            $namespace,
            $name,
            $help,
            $labels,
        );

        return $this->counters[$this->metricIdentifier($namespace, $name)];
    }

    /**
     * @throws MetricNotFoundException
     */
    public function getCounter(string $namespace, string $name): Counter
    {
        $metricIdentifier = $this->metricIdentifier($namespace, $name);
        if (!isset($this->counters[$metricIdentifier])) {
            throw new MetricNotFoundException('Metric not found:' . $metricIdentifier);
        }

        return $this->counters[$this->metricIdentifier($namespace, $name)];
    }

    /**
     * @param string $namespace e.g. cms
     * @param string $name e.g. requests
     * @param string $help e.g. The number of requests made.
     * @param string[] $labels e.g. ['controller', 'action']
     *
     * @throws MetricsRegistrationException
     */
    public function getOrRegisterCounter(string $namespace, string $name, string $help, array $labels = []): Counter
    {
        try {
            $counter = $this->getCounter($namespace, $name);
        } catch (MetricNotFoundException) {
            $counter = $this->registerCounter($namespace, $name, $help, $labels);
        }

        return $counter;
    }

    /**
     * @param string $namespace e.g. cms
     * @param string $name e.g. duration_seconds
     * @param string $help e.g. A histogram of the duration in seconds.
     * @param string[] $labels e.g. ['controller', 'action']
     * @param float[]|null $buckets e.g. [100.0, 200.0, 300.0]
     *
     * @throws MetricsRegistrationException
     */
    public function registerHistogram(
        string $namespace,
        string $name,
        string $help,
        array $labels = [],
        ?array $buckets = null,
    ): Histogram {
        $metricIdentifier = $this->metricIdentifier($namespace, $name);
        if (isset($this->histograms[$metricIdentifier])) {
            throw new MetricsRegistrationException(sprintf('Metric ` . %s . ` already registered', $metricIdentifier));
        }
        $this->histograms[$metricIdentifier] = new Histogram(
            $this->storageAdapter,
            $namespace,
            $name,
            $help,
            $labels,
            $buckets,
        );

        return $this->histograms[$metricIdentifier];
    }

    /**
     * @throws MetricNotFoundException
     */
    public function getHistogram(string $namespace, string $name): Histogram
    {
        $metricIdentifier = $this->metricIdentifier($namespace, $name);
        if (!isset($this->histograms[$metricIdentifier])) {
            throw new MetricNotFoundException('Metric not found:' . $metricIdentifier);
        }

        return $this->histograms[$this->metricIdentifier($namespace, $name)];
    }

    /**
     * @param string $namespace e.g. cms
     * @param string $name e.g. duration_seconds
     * @param string $help e.g. A histogram of the duration in seconds.
     * @param string[] $labels e.g. ['controller', 'action']
     * @param float[]|null $buckets e.g. [100.0, 200.0, 300.0]
     *
     * @throws MetricsRegistrationException
     */
    public function getOrRegisterHistogram(
        string $namespace,
        string $name,
        string $help,
        array $labels = [],
        ?array $buckets = null,
    ): Histogram {
        try {
            $histogram = $this->getHistogram($namespace, $name);
        } catch (MetricNotFoundException) {
            $histogram = $this->registerHistogram($namespace, $name, $help, $labels, $buckets);
        }

        return $histogram;
    }

    /**
     * @param string $namespace e.g. cms
     * @param string $name e.g. duration_seconds
     * @param string $help e.g. A summary of the duration in seconds.
     * @param string[] $labels e.g. ['controller', 'action']
     * @param int $maxAgeSeconds e.g. 604800
     * @param float[]|null $quantiles e.g. [0.01, 0.5, 0.99]
     *
     * @throws MetricsRegistrationException
     */
    public function registerSummary(
        string $namespace,
        string $name,
        string $help,
        array $labels = [],
        int $maxAgeSeconds = 600,
        ?array $quantiles = null,
    ): Summary {
        $metricIdentifier = $this->metricIdentifier($namespace, $name);
        if (isset($this->summaries[$metricIdentifier])) {
            throw new MetricsRegistrationException(sprintf('Metric ` . %s . ` already registered', $metricIdentifier));
        }
        $this->summaries[$metricIdentifier] = new Summary(
            $this->storageAdapter,
            $namespace,
            $name,
            $help,
            $labels,
            $maxAgeSeconds,
            $quantiles,
        );

        return $this->summaries[$metricIdentifier];
    }

    /**
     * @throws MetricNotFoundException
     */
    public function getSummary(string $namespace, string $name): Summary
    {
        $metricIdentifier = $this->metricIdentifier($namespace, $name);
        if (!isset($this->summaries[$metricIdentifier])) {
            throw new MetricNotFoundException('Metric not found:' . $metricIdentifier);
        }

        return $this->summaries[$this->metricIdentifier($namespace, $name)];
    }

    /**
     * @param string $namespace e.g. cms
     * @param string $name e.g. duration_seconds
     * @param string $help e.g. A summary of the duration in seconds.
     * @param string[] $labels e.g. ['controller', 'action']
     * @param int $maxAgeSeconds e.g. 604800
     * @param float[]|null $quantiles e.g. [0.01, 0.5, 0.99]
     *
     * @throws MetricsRegistrationException
     */
    public function getOrRegisterSummary(
        string $namespace,
        string $name,
        string $help,
        array $labels = [],
        int $maxAgeSeconds = 600,
        ?array $quantiles = null,
    ): Summary {
        try {
            $summary = $this->getSummary($namespace, $name);
        } catch (MetricNotFoundException) {
            $summary = $this->registerSummary($namespace, $name, $help, $labels, $maxAgeSeconds, $quantiles);
        }

        return $summary;
    }

    private function metricIdentifier(string $namespace, string $name): string
    {
        return $namespace . ':' . $name;
    }
}
