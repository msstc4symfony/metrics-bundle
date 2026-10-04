<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\DependencyInjection\Fixture;

use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnumInterface;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricTypeEnum;
use Override;

enum ExtraMetricEnum: string implements MetricLabelEnumInterface
{
    case EXTRA = 'config_test_extra';

    #[Override]
    public function getType(): MetricTypeEnum
    {
        return MetricTypeEnum::COUNTER;
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
