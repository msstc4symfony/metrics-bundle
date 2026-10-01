<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Infrastructure\Doctrine\DBAL\Metrics;

use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use PHPUnit\Framework\Assert;
use Prometheus\CollectorRegistry;

final class DbalMetrics
{
    /**
     * @return list<list<string>>
     */
    public static function executed(CollectorRegistry $registry): array
    {
        $executed = [];
        foreach ($registry->getMetricFamilySamples() as $family) {
            if ($family->getName() === 'symfony_' . MetricLabelEnum::DOCTRINE_QUERY_EXECUTE->value) {
                foreach ($family->getSamples() as $sample) {
                    $labels = [];
                    foreach ($sample->getLabelValues() as $value) {
                        Assert::assertIsString($value);
                        $labels[] = $value;
                    }

                    $executed[] = $labels;
                }
            }
        }

        return $executed;
    }
}
