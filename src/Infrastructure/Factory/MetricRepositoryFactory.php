<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Factory;

use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnumInterface;
use MaxShamaev\MetricsBundle\Infrastructure\Repository\MetricRepository;
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
            if (!is_a($metricsEnumClassName, MetricLabelEnumInterface::class, true)) {
                continue;
            }

            foreach ($metricsEnumClassName::cases() as $case) {
                if (is_string($case->value)) {
                    $labels[] = $case;
                }
            }
        }

        return new MetricRepository($labels);
    }
}
