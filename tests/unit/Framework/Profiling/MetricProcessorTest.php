<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Framework\Profiling;

use Msstc4Symfony\MetricsBundle\Framework\Profiling\Processor\EndSpan\MetricProcessor;
use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\ProfilingCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepository;
use Msstc4Symfony\ProfilingBundle\Framework\Span\Span;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;

final class MetricProcessorTest extends TestCase
{
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
        $sums = [];
        foreach ($registry->getMetricFamilySamples() as $family) {
            foreach ($family->getSamples() as $sample) {
                if ($sample->getName() === 'symfony_profiling_span_duration_histogram_seconds_sum') {
                    $sums[] = [array_values(array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $sample->getLabelValues())), (float) $sample->getValue()];
                }
            }
        }

        return $sums;
    }
}
