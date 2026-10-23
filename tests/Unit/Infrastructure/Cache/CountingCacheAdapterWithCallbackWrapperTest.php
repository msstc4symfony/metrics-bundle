<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Infrastructure\Cache;

use LogicException;
use Msstc4Symfony\MetricsBundle\Application\Collector\CacheCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Cache\ComputationCounter;
use Msstc4Symfony\MetricsBundle\Infrastructure\Cache\CountingCacheAdapter;
use Msstc4Symfony\MetricsBundle\Infrastructure\Cache\CountingCallbackWrapper;
use Msstc4Symfony\MetricsBundle\Test\Support\Cache\ContractsPool;
use Msstc4Symfony\MetricsBundle\Test\Support\RecordingMetricRecorder;
use Override;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\ChainAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Pools with a callback wrapper (ContractsTrait) count their computations there: the callback of the application
 * reaches the pool unchanged, so the early-expiration dispatcher can still reverse it.
 */
final class CountingCacheAdapterWithCallbackWrapperTest extends TestCase
{
    private RecordingMetricRecorder $recorder;

    private CacheCollector $collector;

    private ComputationCounter $computations;

    private string $directory;

    #[Override]
    protected function setUp(): void
    {
        $this->recorder = new RecordingMetricRecorder();
        $this->collector = new CacheCollector($this->recorder);
        $this->computations = new ComputationCounter();
        $this->directory = sys_get_temp_dir() . '/msstc4symfony-metrics-cache-' . getmypid();
    }

    #[Override]
    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testCountsAMissWhenThePoolComputesAndAHitOtherwise(): void
    {
        $pool = $this->wrappedPool($this->filesystemPool());
        $computations = 0;
        $compute = static function (ItemInterface $item) use (&$computations): int {
            return ++$computations;
        };

        self::assertSame([1, 1, 1], array_map(static fn (): int => $pool->get('a', $compute), range(1, 3)));
        self::assertSame(['hit' => 2, 'miss' => 1], $this->counts());
    }

    public function testAChainCountsTheComputationOfItsLastAdapter(): void
    {
        $chain = new ChainAdapter([new ArrayAdapter(), $this->filesystemPool()]);
        $chain->setCallbackWrapper(new CountingCallbackWrapper($chain->setCallbackWrapper(null), $this->computations));

        $pool = new CountingCacheAdapter($chain, 'app.chain', $this->collector, $this->computations);
        $computations = 0;
        $compute = static function () use (&$computations): int {
            return ++$computations;
        };

        self::assertSame([1, 1, 1], array_map(static fn (): int => $pool->get('a', $compute), range(1, 3)));
        self::assertSame(['hit' => 2, 'miss' => 1], $this->counts());
    }

    public function testAChainHitInItsLastAdapterOnlyIsAHit(): void
    {
        $filesystem = $this->filesystemPool();
        $filesystem->get('a', static fn (): int => 1);

        $chain = new ChainAdapter([new ArrayAdapter(), $filesystem]);
        $chain->setCallbackWrapper(new CountingCallbackWrapper($chain->setCallbackWrapper(null), $this->computations));

        self::assertSame(1, (new CountingCacheAdapter($chain, 'app.chain', $this->collector, $this->computations))->get('a', static fn (): int => 2));
        self::assertSame(['hit' => 1], $this->counts());
    }

    public function testTheCallbackReachesThePoolUnchanged(): void
    {
        $callback = static fn (): string => 'value';
        $inner = $this->createMock(ContractsPool::class);
        $inner->expects(self::once())->method('get')->with('a', self::identicalTo($callback))->willReturn('value');

        self::assertSame('value', (new CountingCacheAdapter($inner, 'app.users', $this->collector, $this->computations))->get('a', $callback));
        self::assertSame(['hit' => 1], $this->counts());
    }

    public function testAPoolFailingBeforeComputingCountsNothing(): void
    {
        $inner = self::createStub(ContractsPool::class);
        $inner->method('get')->willThrowException(new RuntimeException('backend down'));

        $this->expectExceptionMessage('backend down');

        try {
            (new CountingCacheAdapter($inner, 'app.users', $this->collector, $this->computations))->get('a', static fn (): int => 1);
        } finally {
            self::assertSame([], $this->counts());
        }
    }

    public function testAFailingComputationCountsAMiss(): void
    {
        $pool = $this->wrappedPool($this->filesystemPool());

        $this->expectExceptionMessage('compute failed');

        try {
            $pool->get('a', static function (): int {
                throw new LogicException('compute failed');
            });
        } finally {
            self::assertSame(['miss' => 1], $this->counts());
        }
    }

    public function testWithoutAComputationCounterAPoolFailingBeforeComputingCountsNothing(): void
    {
        $inner = self::createStub(ContractsPool::class);
        $inner->method('get')->willThrowException(new RuntimeException('backend down'));

        $this->expectExceptionMessage('backend down');

        try {
            (new CountingCacheAdapter($inner, 'app.users', $this->collector))->get('a', static fn (): int => 1);
        } finally {
            self::assertSame([], $this->counts());
        }
    }

    public function testASubNamespaceKeepsCountingThroughTheWrapper(): void
    {
        $pool = $this->wrappedPool($this->filesystemPool())->withSubNamespace('tenant');

        $pool->get('a', static fn (): int => 1);
        $pool->get('a', static fn (): int => 2);

        self::assertSame(['hit' => 1, 'miss' => 1], $this->counts());
    }

    public function testAnArrayOfItemsIsCountedAtOnce(): void
    {
        $item = (new ArrayAdapter())->getItem('a');
        $inner = self::createStub(ContractsPool::class);
        $inner->method('getItems')->willReturn(['a' => $item]);

        $items = (new CountingCacheAdapter($inner, 'app.users', $this->collector))->getItems(['a']);

        self::assertSame(['a' => $item], $items);
        self::assertSame(['miss' => 1], $this->counts());
    }

    private function filesystemPool(): FilesystemAdapter
    {
        $pool = new FilesystemAdapter('', 0, $this->directory);
        $pool->clear();

        return $pool;
    }

    private function wrappedPool(FilesystemAdapter $inner): CountingCacheAdapter
    {
        $inner->setCallbackWrapper(new CountingCallbackWrapper($inner->setCallbackWrapper(null), $this->computations));

        return new CountingCacheAdapter($inner, 'app.users', $this->collector, $this->computations);
    }

    /**
     * @return array<string, float|int|null>
     */
    private function counts(): array
    {
        $this->collector->flush();
        $counts = [];
        foreach ($this->recorder->calls as [, , $value, $labels]) {
            $counts[(string) ($labels[1] ?? '')] = $value;
        }

        $this->recorder->calls = [];
        ksort($counts);

        return $counts;
    }
}
