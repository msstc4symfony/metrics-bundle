<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Framework\Profiling;

use Msstc4Symfony\MetricsBundle\Framework\Profiling\Processor\EndSpan\MetricProcessor;
use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\ProfilingCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepository;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;
use Msstc4Symfony\ProfilingBundle\Framework\Processor\EndSpan\EndSpanProcessorInterface;
use Msstc4Symfony\ProfilingBundle\Framework\Span\Span;
use Override;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;

final class MetricProcessorTest extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        if (!interface_exists(EndSpanProcessorInterface::class)) {
            self::markTestSkipped('msstc4symfony/profiling-bundle not installed');
        }
    }

    public function testRecordsTheDurationFixedWhenTheSpanEnded(): void
    {
        $registry = new CollectorRegistry(new InMemory());
        $processor = new MetricProcessor(new ProfilingCollector($registry, new MetricRepository([]), 'app', 'cmp'));
        $span = new Span('Import Orders');
        $span->end();
        usleep(2000);

        $processor->process($span, []);

        self::assertSame([[['app', 'cmp', 'import_orders'], round($span->getDuration(), 6)]], $this->sums($registry));
    }

    /**
     * @return list<array{list<string>, float}>
     */
    private function sums(CollectorRegistry $registry): array
    {
        return array_map(
            static fn (array $sample): array => [$sample[0], (float) $sample[1]],
            RegistrySamples::samples($registry, MetricLabelEnum::PROFILING_SPAN_DURATION_HISTOGRAM_SECONDS, '_sum'),
        );
    }
}
