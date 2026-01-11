<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Factory;

use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnumInterface;
use MaxShamaev\MetricsBundle\Infrastructure\Repository\MetricRepository;
use StringBackedEnum;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class MetricRepositoryFactory
{
    /**
     * @param class-string[] $metricsEnumClassNames
     */
    public function __construct(
        #[Autowire(param: 'metrics_bundle.metric_enums')]
        private readonly array $metricsEnumClassNames,
    ) {
    }

    public function create(): MetricRepository
    {
        $labels = [];

        foreach ($this->metricsEnumClassNames as $metricsEnumClassName) {
            if (
                !is_a($metricsEnumClassName, MetricLabelEnumInterface::class, true)
                || !is_a($metricsEnumClassName, StringBackedEnum::class, true)
            ) {
                continue;
            }

            $labels[] = $metricsEnumClassName::cases();
        }

        return new MetricRepository(array_merge(...$labels));
    }
}
