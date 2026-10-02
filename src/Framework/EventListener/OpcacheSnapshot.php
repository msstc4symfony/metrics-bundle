<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Framework\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * OPcache gauges as ratios; a gauge whose source value is missing or not numeric is null.
 *
 * @internal
 */
#[Exclude]
final readonly class OpcacheSnapshot
{
    public function __construct(
        public ?float $memoryUsed,
        public ?float $memoryWasted,
        public int|float|null $cachedScripts,
        public ?float $hitRate,
    ) {
    }

    /**
     * @param array<array-key, mixed> $status result of opcache_get_status(false), not trusted to match its documented shape
     */
    public static function fromStatus(array $status): self
    {
        $memory = self::section($status, 'memory_usage');
        $statistics = self::section($status, 'opcache_statistics');

        $used = self::readNumber($memory, 'used_memory');
        $free = self::readNumber($memory, 'free_memory');

        return new self(
            $used !== null && $free !== null && $used + $free > 0 ? round($used / ($used + $free), 4) : null,
            self::percentageAsRatio(self::readNumber($memory, 'current_wasted_percentage')),
            self::readNumber($statistics, 'num_cached_scripts'),
            self::percentageAsRatio(self::readNumber($statistics, 'opcache_hit_rate')),
        );
    }

    /**
     * @param array<array-key, mixed> $status
     *
     * @return array<array-key, mixed>
     */
    private static function section(array $status, string $key): array
    {
        $section = $status[$key] ?? null;

        return \is_array($section) ? $section : [];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function readNumber(array $data, string $key): int|float|null
    {
        $value = $data[$key] ?? null;

        return \is_int($value) || \is_float($value) ? $value : null;
    }

    private static function percentageAsRatio(int|float|null $percentage): ?float
    {
        return $percentage === null ? null : round($percentage / 100, 4);
    }
}
