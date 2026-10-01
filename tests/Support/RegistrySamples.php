<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Support;

use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use PHPUnit\Framework\Assert;
use Prometheus\RegistryInterface;

final class RegistrySamples
{
    /**
     * Label values of every sample named after the metric plus the suffix ("_count", "_sum", ...).
     *
     * @return list<list<string>>
     */
    public static function labels(RegistryInterface $registry, MetricLabelEnum $metric, string $suffix = ''): array
    {
        return array_map(static fn (array $sample): array => $sample[0], self::samples($registry, $metric, $suffix));
    }

    /**
     * @return list<array{list<string>, string}> label values and value per sample
     */
    public static function samples(RegistryInterface $registry, MetricLabelEnum $metric, string $suffix = ''): array
    {
        $name = 'symfony_' . $metric->value;
        $samples = [];
        foreach ($registry->getMetricFamilySamples() as $family) {
            if ($family->getName() !== $name) {
                continue;
            }

            foreach ($family->getSamples() as $sample) {
                if ($sample->getName() !== $name . $suffix) {
                    continue;
                }

                $labels = [];
                foreach ($sample->getLabelValues() as $value) {
                    Assert::assertIsString($value);
                    $labels[] = $value;
                }

                $samples[] = [$labels, (string) $sample->getValue()];
            }
        }

        return $samples;
    }
}
