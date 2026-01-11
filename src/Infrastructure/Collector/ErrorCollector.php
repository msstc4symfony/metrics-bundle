<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Collector;

use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Monolog\Level;
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

            $counter->inc($this->prepareLabelValues([$throwable::class]));
        } catch (Throwable) {
        }
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
