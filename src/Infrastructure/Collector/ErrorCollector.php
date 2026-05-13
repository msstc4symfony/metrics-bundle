<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Collector;

use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use MaxShamaev\MetricsBundle\Infrastructure\Repository\MetricRepositoryInterface;
use Monolog\Level;
use Prometheus\RegistryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Throwable;

class ErrorCollector extends AbstractCollector
{
    /**
     * @var class-string[]
     */
    private array $ignoredExceptions = [
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
        if (in_array($throwable::class, $this->ignoredExceptions, true)) {
            return;
        }

        $metric = $this->repository->find(MetricLabelEnum::EXCEPTION);

        try {
            $counter = $this->registry->getOrRegisterCounter(
                $this->namespace,
                $metric->name->value,
                $metric->description,
                $metric->getLabelNames(),
            );

            $counter->inc($this->prepareLabelValues([$this->formatExceptionClass($throwable)]));
        } catch (Throwable) {
        }
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

    public function incError(Level $level): void
    {
        $metric = $this->repository->find(MetricLabelEnum::ERROR);

        try {
            $counter = $this->registry->getOrRegisterCounter(
                $this->namespace,
                $metric->name->value,
                $metric->description,
                $metric->getLabelNames(),
            );

            $counter->inc($this->prepareLabelValues([$level->getName()]));
        } catch (Throwable) {
        }
    }
}
