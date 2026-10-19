<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\DependencyInjection\Fixture;

use Msstc4Symfony\MetricsBundle\Application\Metric\MetricDefinitionInterface;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricType;
use Override;

enum ExtraMetricEnum: string implements MetricDefinitionInterface
{
    case EXTRA = 'config_test_extra';

    #[Override]
    public function getType(): MetricType
    {
        return MetricType::COUNTER;
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Extra metric registered through the bundle configuration';
    }

    #[Override]
    public function getLabels(): array
    {
        return [];
    }

    #[Override]
    public function getBatches(): array
    {
        return [];
    }
}
