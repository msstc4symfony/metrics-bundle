<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Framework\Profiling\Processor\EndSpan;

use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\ProfilingCollector;
use Msstc4Symfony\ProfilingBundle\Framework\Processor\EndSpan\EndSpanProcessorInterface;
use Msstc4Symfony\ProfilingBundle\Framework\Span\SpanInterface;

/**
 * Active only while msstc4symfony/metrics-bridge-profiling is not installed; the bridge
 * replaces it.
 *
 * @deprecated since 1.2, use msstc4symfony/metrics-bridge-profiling; removed in 2.0
 */
final readonly class MetricProcessor implements EndSpanProcessorInterface
{
    public function __construct(
        private ProfilingCollector $profilingCollector,
    ) {
        trigger_deprecation('msstc4symfony/metrics-bundle', '1.2', 'Span metrics from "%s" are deprecated, install "msstc4symfony/metrics-bridge-profiling" instead.', self::class);
    }

    public function process(SpanInterface $span, array $context): void
    {
        $this->profilingCollector->setProfilingSpanDuration(
            $span->getMessage(),
            round($span->getDuration(), 6),
        );
    }
}
