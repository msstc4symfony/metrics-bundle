<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Factory;

use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnumInterface;
use MaxShamaev\MetricsBundle\Infrastructure\Repository\MetricRepository;
use ReflectionEnum;
use ReflectionNamedType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class MetricRepositoryFactory
{
    /**
     * @param class-string[] $metricsEnumClassNames
     */
    public function __construct(
        #[Autowire(param: 'metrics_bundle.metric_enums')]
        private array $metricsEnumClassNames,
    ) {
    }

    public function create(): MetricRepository
    {
        $labels = [];

        foreach ($this->metricsEnumClassNames as $metricsEnumClassName) {
            if (!is_a($metricsEnumClassName, MetricLabelEnumInterface::class, true)) {
                continue;
            }

            $backingType = new ReflectionEnum($metricsEnumClassName)->getBackingType();
            if (!$backingType instanceof ReflectionNamedType || $backingType->getName() !== 'string') {
                continue;
            }

            foreach ($metricsEnumClassName::cases() as $case) {
                $labels[] = $case;
            }
        }

        return new MetricRepository($labels);
    }
}
