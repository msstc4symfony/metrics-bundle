<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Framework\EventListener;

use Msstc4Symfony\MetricsBundle\Framework\EventListener\InfoEventListener;
use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\InfoCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepository;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;
use Override;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class InfoEventListenerTest extends TestCase
{
    private CollectorRegistry $registry;

    private InfoEventListener $listener;

    #[Override]
    protected function setUp(): void
    {
        if (!is_readable('/proc/meminfo') || !is_readable('/proc/cpuinfo')) {
            self::markTestSkipped('Needs Linux /proc');
        }

        $this->registry = new CollectorRegistry(new InMemory());
        $this->listener = new InfoEventListener(
            new InfoCollector($this->registry, new MetricRepository([]), 'app', 'cmp'),
            new ArrayAdapter(),
        );
    }

    public function testRecordsHostGaugesAsRatios(): void
    {
        $this->listener->onTerminate();

        foreach ([MetricLabelEnum::INFO_MEMORY_USED, MetricLabelEnum::INFO_FILESYSTEM_USED] as $metric) {
            $samples = RegistrySamples::samples($this->registry, $metric);
            self::assertCount(1, $samples, $metric->value);
            self::assertSame(['app', 'cmp'], $samples[0][0]);
            self::assertGreaterThan(0.0, (float) $samples[0][1], $metric->value);
            self::assertLessThanOrEqual(1.0, (float) $samples[0][1], $metric->value);
        }

        $cpu = RegistrySamples::samples($this->registry, MetricLabelEnum::INFO_CPU_LOAD);
        self::assertCount(1, $cpu);
        self::assertGreaterThanOrEqual(0.0, (float) $cpu[0][1]);
    }

    public function testRecordsOpcacheGaugesWhenOpcacheIsActive(): void
    {
        $status = \function_exists('opcache_get_status') ? opcache_get_status(false) : false;
        if (!\is_array($status) || !isset($status['memory_usage'], $status['opcache_statistics'])) {
            self::markTestSkipped('OPcache is not active in this SAPI (opcache.enable_cli)');
        }

        $this->listener->onTerminate();

        foreach ([MetricLabelEnum::INFO_OPCACHE_MEMORY_USED, MetricLabelEnum::INFO_OPCACHE_MEMORY_WASTED, MetricLabelEnum::INFO_OPCACHE_HIT_RATE] as $metric) {
            $samples = RegistrySamples::samples($this->registry, $metric);
            self::assertCount(1, $samples, $metric->value);
            self::assertGreaterThanOrEqual(0.0, (float) $samples[0][1], $metric->value);
            self::assertLessThanOrEqual(1.0, (float) $samples[0][1], $metric->value);
        }

        $scripts = RegistrySamples::samples($this->registry, MetricLabelEnum::INFO_OPCACHE_CACHED_SCRIPTS);
        self::assertCount(1, $scripts);
        self::assertGreaterThan(0, (int) $scripts[0][1]);
    }

    public function testCollectsOncePerPeriod(): void
    {
        $this->listener->onTerminate();
        $this->registry->wipeStorage();

        $this->listener->onTerminate();

        self::assertFalse(RegistrySamples::exists($this->registry, MetricLabelEnum::INFO_MEMORY_USED));
    }
}
