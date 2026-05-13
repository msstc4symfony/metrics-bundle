<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Framework\Profiling\Processor\EndSpan;

use MaxShamaev\MetricsBundle\Infrastructure\Collector\ProfilingCollector;
use MaxShamaev\ProfilingBundle\Framework\Processor\EndSpan\EndSpanProcessorInterface;
use MaxShamaev\ProfilingBundle\Framework\Span\SpanInterface;

final readonly class MetricProcessor implements EndSpanProcessorInterface
{
    public function __construct(
        private ProfilingCollector $profilingCollector,
    ) {
    }

    public function process(SpanInterface $span, array $context): void
    {
        $this->profilingCollector->setProfilingSpanDuration(
            $span->getMessage(),
            round(microtime(true) - $span->getStartTime(), 6),
        );
    }
}
