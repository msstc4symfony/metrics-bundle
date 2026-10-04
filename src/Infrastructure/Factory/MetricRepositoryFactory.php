<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Factory;

use LogicException;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnumInterface;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepository;
use ReflectionEnum;
use ReflectionNamedType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class MetricRepositoryFactory
{
    /**
     * Not narrowed to MetricLabelEnumInterface: compiler passes append to the parameter unvalidated, create() guards each entry.
     *
     * @param list<class-string> $metricsEnumClassNames
     */
    public function __construct(
        #[Autowire(param: 'msstc4symfony_metrics.metric_enums')]
        private array $metricsEnumClassNames,
    ) {
    }

    /**
     * @throws LogicException when two enums declare the same metric name
     */
    public function create(): MetricRepository
    {
        $labels = [];

        foreach (array_unique($this->metricsEnumClassNames) as $metricsEnumClassName) {
            if (!is_a($metricsEnumClassName, MetricLabelEnumInterface::class, true)) {
                continue;
            }

            $backingType = new ReflectionEnum($metricsEnumClassName)->getBackingType();
            if (!$backingType instanceof ReflectionNamedType || $backingType->getName() !== 'string') {
                continue;
            }

            foreach ($metricsEnumClassName::cases() as $case) {
                $declared = $labels[$case->value] ?? null;
                if ($declared !== null) {
                    throw new LogicException(\sprintf('Metric "%s" is declared by both %s::%s and %s::%s.', $case->value, $declared::class, $declared->name, $case::class, $case->name));
                }

                $labels[$case->value] = $case;
            }
        }

        return new MetricRepository(array_values($labels));
    }
}
