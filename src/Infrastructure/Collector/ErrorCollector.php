<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Collector;

use Monolog\Level;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepositoryInterface;
use Override;
use Prometheus\RegistryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Throwable;

final class ErrorCollector extends AbstractCollector
{
    /**
     * @var class-string[]
     */
    private const array IGNORED_EXCEPTIONS = [
        NotFoundHttpException::class,
        AccessDeniedHttpException::class,
        MethodNotAllowedHttpException::class,
        AccessDeniedException::class,
    ];

    public function __construct(
        RegistryInterface $registry,
        MetricRepositoryInterface $repository,
        string $applicationName,
        string $componentName,
        #[Autowire(param: 'metrics_bundle.exceptionLabelShortClassName')]
        private readonly bool $useShortExceptionClassName = false,
    ) {
        parent::__construct($registry, $repository, $applicationName, $componentName);
    }

    public function incException(Throwable $throwable): void
    {
        if (in_array($throwable::class, self::IGNORED_EXCEPTIONS, true)) {
            return;
        }

        $this->incCounter(MetricLabelEnum::EXCEPTION, [$this->formatExceptionClass($throwable)]);
    }

    public function incError(Level $level): void
    {
        $this->incCounter(MetricLabelEnum::ERROR, [$level->getName()]);
    }

    /**
     * Deliberate no-op: logging from the error collector would recurse through
     * HandlerDecorator and re-enter incError().
     */
    #[Override]
    protected function processException(Throwable $exception, int|string $metricName): void
    {
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
