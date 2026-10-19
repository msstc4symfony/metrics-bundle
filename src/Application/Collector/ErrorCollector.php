<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Application\Collector;

use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Application\Port\MetricRecorderInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Throwable;

/**
 * @internal
 */
final readonly class ErrorCollector
{
    /**
     * @var list<string> FQCNs; the Security one may be absent, so not class-string
     */
    private const IGNORED_EXCEPTIONS = [
        NotFoundHttpException::class,
        AccessDeniedHttpException::class,
        MethodNotAllowedHttpException::class,
        AccessDeniedException::class,
    ];

    public function __construct(
        private MetricRecorderInterface $recorder,
        #[Autowire(param: 'msstc4symfony_metrics.errors.short_exception_class_name')]
        private bool $useShortExceptionClassName,
    ) {
    }

    public function incException(Throwable $throwable): void
    {
        if (in_array($throwable::class, self::IGNORED_EXCEPTIONS, true)) {
            return;
        }

        $this->recorder->increment(BuiltInMetric::EXCEPTION, [$this->formatExceptionClass($throwable)]);
    }

    public function incError(string $level): void
    {
        $this->recorder->increment(BuiltInMetric::ERROR, [$level]);
    }

    private function formatExceptionClass(Throwable $throwable): string
    {
        $class = $throwable::class;

        if (!$this->useShortExceptionClassName) {
            return $class;
        }

        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }
}
