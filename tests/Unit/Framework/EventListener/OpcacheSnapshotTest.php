<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Framework\EventListener;

use Msstc4Symfony\MetricsBundle\Framework\EventListener\OpcacheSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpcacheSnapshotTest extends TestCase
{
    /**
     * @return iterable<string, array{array<array-key, mixed>, float|null, float|null, int|float|null, float|null}>
     */
    public static function statusProvider(): iterable
    {
        yield 'regular status' => [
            [
                'memory_usage' => ['used_memory' => 30, 'free_memory' => 70, 'current_wasted_percentage' => 1.23456],
                'opcache_statistics' => ['num_cached_scripts' => 412, 'opcache_hit_rate' => 99.123456],
            ],
            0.3,
            0.0123,
            412,
            0.9912,
        ];
        yield 'empty status' => [[], null, null, null, null];
        yield 'sections that are not arrays' => [['memory_usage' => 'n/a', 'opcache_statistics' => 7], null, null, null, null];
        yield 'non-numeric values' => [
            [
                'memory_usage' => ['used_memory' => '30', 'free_memory' => 70, 'current_wasted_percentage' => null],
                'opcache_statistics' => ['num_cached_scripts' => '412', 'opcache_hit_rate' => true],
            ],
            null,
            null,
            null,
            null,
        ];
        yield 'empty memory pool' => [
            ['memory_usage' => ['used_memory' => 0, 'free_memory' => 0, 'current_wasted_percentage' => 0]],
            null,
            0.0,
            null,
            null,
        ];
        yield 'missing free memory' => [['memory_usage' => ['used_memory' => 10]], null, null, null, null];
        yield 'float counters' => [
            [
                'memory_usage' => ['used_memory' => 1.0, 'free_memory' => 2.0],
                'opcache_statistics' => ['num_cached_scripts' => 3.0, 'opcache_hit_rate' => 50],
            ],
            0.3333,
            null,
            3.0,
            0.5,
        ];
    }

    /**
     * @param array<array-key, mixed> $status
     */
    #[DataProvider('statusProvider')]
    public function testReadsGaugesFromStatus(
        array $status,
        ?float $memoryUsed,
        ?float $memoryWasted,
        int|float|null $cachedScripts,
        ?float $hitRate,
    ): void {
        $snapshot = OpcacheSnapshot::fromStatus($status);

        self::assertSame($memoryUsed, $snapshot->memoryUsed);
        self::assertSame($memoryWasted, $snapshot->memoryWasted);
        self::assertSame($cachedScripts, $snapshot->cachedScripts);
        self::assertSame($hitRate, $snapshot->hitRate);
    }
}
