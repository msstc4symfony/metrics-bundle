<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Application\Metric;

use LogicException;
use ReflectionEnum;
use ReflectionNamedType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * @internal
 */
final readonly class MetricRepositoryFactory
{
    /**
     * Not narrowed to MetricDefinitionInterface: compiler passes append to the parameter unvalidated, create() guards each entry.
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
            if (!is_a($metricsEnumClassName, MetricDefinitionInterface::class, true)) {
                continue;
            }

            $backingType = (new ReflectionEnum($metricsEnumClassName))->getBackingType();
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
