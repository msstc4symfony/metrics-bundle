<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Framework\EventListener;

use DateInterval;
use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\InfoCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Psr\Cache\CacheItemInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Contracts\Cache\CacheInterface;

#[AsEventListener(event: 'kernel.terminate', method: 'onTerminate', priority: -4096)]
final readonly class InfoEventListener
{
    private const string DEFAULT_PERIOD = 'PT1M';

    private DateInterval $metricsCollectPeriod;

    public function __construct(
        private InfoCollector $infoCollector,
        private CacheInterface $metricsCache,
        string $metricsCollectPeriod = self::DEFAULT_PERIOD,
    ) {
        $this->metricsCollectPeriod = new DateInterval($metricsCollectPeriod);
    }

    public function onTerminate(): void
    {
        $id = bin2hex(random_bytes(16));

        $cachedId = $this->metricsCache->get(
            'metrics_last_info_collect_datetime',
            function (CacheItemInterface $item) use ($id): string {
                $item->expiresAfter($this->metricsCollectPeriod);

                return $id;
            },
        );

        if ($cachedId !== $id) {
            return;
        }

        // CPU load
        $value = $this->getCPULoad();
        if ($value !== null) {
            $this->infoCollector->setMetric(MetricLabelEnum::INFO_CPU_LOAD, $value);
        }

        // Memory usage
        $value = $this->getMemoryUsage();
        if ($value !== null) {
            $this->infoCollector->setMetric(MetricLabelEnum::INFO_MEMORY_USED, $value);
        }

        // Filesystem usage
        $value = $this->getFilesystemUsage();
        if ($value !== null) {
            $this->infoCollector->setMetric(MetricLabelEnum::INFO_FILESYSTEM_USED, $value);
        }

        $this->collectOpcache();

        // FPM
        if (function_exists('fpm_get_status')) {
            $data = fpm_get_status();

            if (is_array($data)) {
                $this->infoCollector->setMetric(MetricLabelEnum::INFO_FPM_IDLE_PROCESSES, $data['idle-processes']);
                $this->infoCollector->setMetric(MetricLabelEnum::INFO_FPM_ACTIVE_PROCESSES, $data['active-processes']);
                $this->infoCollector->setMetric(MetricLabelEnum::INFO_FPM_TOTAL_PROCESSES, $data['total-processes']);
                $this->infoCollector->setMetric(
                    MetricLabelEnum::INFO_FPM_MAX_ACTIVE_PROCESSES,
                    $data['max-active-processes'],
                );
                $this->infoCollector->setMetric(MetricLabelEnum::INFO_FPM_LISTEN_QUEUE, $data['listen-queue']);
                $this->infoCollector->setMetric(MetricLabelEnum::INFO_FPM_MAX_LISTEN_QUEUE, $data['max-listen-queue']);
                $this->infoCollector->setMetric(MetricLabelEnum::INFO_FPM_LISTEN_QUEUE_SIZE, $data['listen-queue-len']);
            }
        }
    }

    private function collectOpcache(): void
    {
        if (!function_exists('opcache_get_status')) {
            return;
        }

        $status = opcache_get_status(false);
        if (!is_array($status)) {
            return;
        }

        $memory = $status['memory_usage'] ?? null;
        if (is_array($memory)) {
            $used = $this->number($memory, 'used_memory');
            $free = $this->number($memory, 'free_memory');
            if ($used !== null && $free !== null && $used + $free > 0) {
                $this->infoCollector->setMetric(MetricLabelEnum::INFO_OPCACHE_MEMORY_USED, round($used / ($used + $free), 4));
            }

            $wasted = $this->number($memory, 'current_wasted_percentage');
            if ($wasted !== null) {
                $this->infoCollector->setMetric(MetricLabelEnum::INFO_OPCACHE_MEMORY_WASTED, round($wasted / 100, 4));
            }
        }

        $statistics = $status['opcache_statistics'] ?? null;
        if (is_array($statistics)) {
            $cachedScripts = $this->number($statistics, 'num_cached_scripts');
            if ($cachedScripts !== null) {
                $this->infoCollector->setMetric(MetricLabelEnum::INFO_OPCACHE_CACHED_SCRIPTS, $cachedScripts);
            }

            $hitRate = $this->number($statistics, 'opcache_hit_rate');
            if ($hitRate !== null) {
                $this->infoCollector->setMetric(MetricLabelEnum::INFO_OPCACHE_HIT_RATE, round($hitRate / 100, 4));
            }
        }
    }

    /**
     * @param array<mixed> $data
     */
    private function number(array $data, string $key): int|float|null
    {
        $value = $data[$key] ?? null;

        return is_int($value) || is_float($value) ? $value : null;
    }

    private function getCPULoad(): ?float
    {
        $data = sys_getloadavg();
        if ($data === false) {
            return null;
        }

        $cpuInfo = file_get_contents('/proc/cpuinfo');
        if (!is_string($cpuInfo)) {
            return null;
        }

        $cpuCount = substr_count($cpuInfo, 'processor');
        if ($cpuCount <= 0) {
            return null;
        }

        return round($data[0] / $cpuCount, 4);
    }

    private function getMemoryUsage(): ?float
    {
        $data = file_get_contents('/proc/meminfo');
        if ($data === false) {
            return null;
        }

        $lines = explode("\n", $data);
        $meminfo = [];
        foreach ($lines as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $matches) === 1) {
                $meminfo[$matches[1]] = (int) $matches[2];
            }
        }

        if (
            !isset($meminfo['MemTotal'])
            || !isset($meminfo['MemFree'])
            || !isset($meminfo['Buffers'])
            || !isset($meminfo['Cached'])
        ) {
            return null;
        }

        $total = $meminfo['MemTotal'];
        $free = $meminfo['MemFree'] + $meminfo['Buffers'] + $meminfo['Cached'];
        $used = $total - $free;

        return round($used / $total, 4);
    }

    private function getFilesystemUsage(): ?float
    {
        $total = disk_total_space(__DIR__);
        $free = disk_free_space(__DIR__);
        if ($total === false || $free === false) {
            return null;
        }

        $used = $total - $free;

        return round($used / $total, 4);
    }
}
