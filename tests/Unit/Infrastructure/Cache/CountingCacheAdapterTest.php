<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Infrastructure\Cache;

use BadMethodCallException;
use Msstc4Symfony\MetricsBundle\Application\Collector\CacheCollector;
use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Infrastructure\Cache\CountingCacheAdapter;
use Msstc4Symfony\MetricsBundle\Test\Support\Cache\ContractsPool;
use Msstc4Symfony\MetricsBundle\Test\Support\Cache\PruneableContractsPool;
use Msstc4Symfony\MetricsBundle\Test\Support\RecordingMetricRecorder;
use Override;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use RuntimeException;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\NamespacedPoolInterface;

final class CountingCacheAdapterTest extends TestCase
{
    private RecordingMetricRecorder $recorder;

    private CacheCollector $collector;

    private ArrayAdapter $inner;

    private CountingCacheAdapter $pool;

    #[Override]
    protected function setUp(): void
    {
        $this->recorder = new RecordingMetricRecorder();
        $this->collector = new CacheCollector($this->recorder);
        $this->inner = new ArrayAdapter();
        $this->pool = new CountingCacheAdapter($this->inner, 'app.users', $this->collector);
    }

    public function testGetItemCountsAMissAndAHit(): void
    {
        $item = $this->pool->getItem('a');
        self::assertFalse($item->isHit());
        self::assertTrue($this->pool->save($item->set(1)));

        self::assertSame(1, $this->pool->getItem('a')->get());
        self::assertSame(['hit' => 1, 'miss' => 1], $this->counts());
    }

    public function testGetItemsCountsEveryItemOnceItIsIterated(): void
    {
        $this->inner->save($this->inner->getItem('a')->set(1));

        $items = $this->pool->getItems(['a', 'b', 'c']);
        self::assertSame([], $this->counts(), 'lookups are counted while iterating');

        $hits = [];
        foreach ($items as $key => $item) {
            $hits[$key] = $item->isHit();
        }

        self::assertSame(['a' => true, 'b' => false, 'c' => false], $hits);
        self::assertSame(['hit' => 1, 'miss' => 2], $this->counts());
    }

    public function testGetItemsAsksThePoolBeforeTheItemsAreIterated(): void
    {
        $inner = $this->createMock(ContractsPool::class);
        $inner->expects(self::once())->method('getItems')->with(['a'])->willReturn([]);

        (new CountingCacheAdapter($inner, 'app.users', $this->collector))->getItems(['a']);
    }

    public function testHasItemIsNotCounted(): void
    {
        $this->inner->save($this->inner->getItem('a')->set(1));

        self::assertTrue($this->pool->hasItem('a'));
        self::assertFalse($this->pool->hasItem('b'));
        self::assertSame([], $this->counts());
    }

    public function testGetCountsAMissWhenTheCallbackRunsAndAHitOtherwise(): void
    {
        $computations = 0;
        $compute = static function (ItemInterface $item) use (&$computations): int {
            self::assertSame('a', $item->getKey());

            return ++$computations;
        };

        self::assertSame([1, 1, 1], array_map(fn (): int => $this->pool->get('a', $compute), range(1, 3)));

        self::assertSame(['hit' => 2, 'miss' => 1], $this->counts());
    }

    public function testGetKeepsTheSaveFlagOfTheCallback(): void
    {
        $dontSave = static function (CacheItemInterface $item, bool &$save): string {
            $save = false;

            return 'transient';
        };

        self::assertSame('transient', $this->pool->get('a', $dontSave));
        self::assertFalse($this->inner->hasItem('a'));
        self::assertSame('again', $this->pool->get('a', static fn (): string => 'again'));
        self::assertSame(['miss' => 2], $this->counts());
    }

    public function testGetForwardsBetaAndMetadata(): void
    {
        $inner = self::createStub(ContractsPool::class);
        $inner->method('get')->willReturnCallback(static function (string $key, callable $callback, ?float $beta, ?array &$metadata): string {
            $metadata = ['beta' => $beta];

            return $key;
        });
        $metadata = null;

        self::assertSame('a', (new CountingCacheAdapter($inner, 'app.users', $this->collector))->get('a', static fn (): string => 'x', 2.5, $metadata));
        self::assertSame(['beta' => 2.5], $metadata);
        self::assertSame(['hit' => 1], $this->counts());
    }

    public function testAFailingCallbackStillCountsAMiss(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('compute failed');

        try {
            $this->pool->get('a', static function (): string {
                throw new RuntimeException('compute failed');
            });
        } finally {
            self::assertSame(['miss' => 1], $this->counts());
        }
    }

    public function testTagVersionLookupsOfATagAwareAdapterOnTopAreNotCounted(): void
    {
        $tagAware = new TagAwareAdapter($this->pool);

        $tagAware->get('a', static function (ItemInterface $item): int {
            $item->tag(['users']);

            return 1;
        });
        $this->collector->flush();
        $this->recorder->calls = [];

        self::assertSame(1, $tagAware->get('a', static fn (): int => 2));
        self::assertSame(['hit' => 1], $this->counts());
    }

    public function testTagKeysOfATagAwareAdapterOnTopAreNotCounted(): void
    {
        $this->pool->getItem('users' . TagAwareAdapter::TAGS_PREFIX);
        foreach ($this->pool->getItems(["\0tags\0a", 'orders' . TagAwareAdapter::TAGS_PREFIX, 'a']) as $item) {
            self::assertFalse($item->isHit());
        }

        self::assertSame(['miss' => 1], $this->counts());
    }

    public function testWritesAndDeletesReachTheInnerPool(): void
    {
        self::assertTrue($this->pool->save($this->pool->getItem('a')->set(1)));
        self::assertTrue($this->pool->saveDeferred($this->pool->getItem('b')->set(2)));
        self::assertTrue($this->pool->commit());
        self::assertTrue($this->inner->hasItem('b'));

        self::assertTrue($this->pool->deleteItem('a'));
        self::assertFalse($this->inner->hasItem('a'));
        self::assertTrue($this->pool->delete('b'));
        self::assertFalse($this->inner->hasItem('b'));

        $this->inner->save($this->inner->getItem('c')->set(3));
        $this->inner->save($this->inner->getItem('d')->set(4));
        self::assertTrue($this->pool->deleteItems(['c']));
        self::assertFalse($this->inner->hasItem('c'));
        self::assertTrue($this->pool->clear());
        self::assertFalse($this->inner->hasItem('d'));
    }

    public function testClearForwardsThePrefix(): void
    {
        $inner = $this->createMock(ContractsPool::class);
        $inner->expects(self::once())->method('clear')->with('users.')->willReturn(true);

        self::assertTrue((new CountingCacheAdapter($inner, 'app.users', $this->collector))->clear('users.'));
    }

    public function testPruneReachesAPruneablePool(): void
    {
        $inner = $this->createMock(PruneableContractsPool::class);
        $inner->expects(self::once())->method('prune')->willReturn(true);

        self::assertTrue((new CountingCacheAdapter($inner, 'app.users', $this->collector))->prune());
        self::assertFalse($this->pool->prune());
    }

    public function testResetReachesTheInnerPool(): void
    {
        $this->inner->save($this->inner->getItem('a')->set(1));

        $this->pool->reset();

        self::assertFalse($this->inner->hasItem('a'));
    }

    public function testASubNamespaceIsCountedUnderTheSamePool(): void
    {
        $sub = $this->pool->withSubNamespace('tenant');

        self::assertNotSame($this->pool, $sub);
        $sub->getItem('a');
        self::assertSame(['miss' => 1], $this->counts());
    }

    public function testGetNeedsAContractsPool(): void
    {
        $inner = self::createStub(AdapterInterface::class);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('::get()": this class doesn\'t implement "' . CacheInterface::class . '".');

        (new CountingCacheAdapter($inner, 'app.users', $this->collector))->get('a', static fn (): int => 1);
    }

    public function testASubNamespaceNeedsANamespacedPool(): void
    {
        $inner = self::createStub(AdapterInterface::class);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('::withSubNamespace()": this class doesn\'t implement "' . NamespacedPoolInterface::class . '".');

        (new CountingCacheAdapter($inner, 'app.users', $this->collector))->withSubNamespace('tenant');
    }

    /**
     * @return array<string, int|float|null>
     */
    private function counts(): array
    {
        $this->collector->flush();
        $counts = [];
        foreach ($this->recorder->calls as [$method, $metric, $value, $labels]) {
            self::assertSame(['incrementBy', BuiltInMetric::CACHE_REQUEST, 'app.users'], [$method, $metric, $labels[0] ?? null]);
            $counts[(string) ($labels[1] ?? '')] = $value;
        }

        $this->recorder->calls = [];
        ksort($counts);

        return $counts;
    }
}
