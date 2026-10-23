<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Presentation\EventListener;

use Closure;
use DateInterval;
use Msstc4Symfony\MetricsBundle\Application\Collector\MessengerCollector;
use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Presentation\EventListener\MessengerQueueDepthListener;
use Msstc4Symfony\MetricsBundle\Test\Support\CollectingLogger;
use Msstc4Symfony\MetricsBundle\Test\Support\RecordingMetricRecorder;
use Msstc4Symfony\MetricsBundle\Test\Support\StubQueueDepthReader;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\Cache\CacheInterface;

#[RequiresMethod(MockClock::class, 'sleep')]
final class MessengerQueueDepthListenerTest extends TestCase
{
    private RecordingMetricRecorder $recorder;

    private CollectingLogger $logger;

    private MockClock $clock;

    private StubQueueDepthReader $reader;

    #[Override]
    protected function setUp(): void
    {
        $this->recorder = new RecordingMetricRecorder();
        $this->logger = new CollectingLogger();
        // Starts at the real time: CacheItem::expiresAfter() computes the expiry with microtime().
        $this->clock = new MockClock();
        $this->reader = new StubQueueDepthReader(['async' => 7, 'other' => 3, 'sync' => null, 'down' => new RuntimeException('broker unreachable')]);
    }

    public function testSamplesTheDepthAndTheTimeOfEachCountableTransport(): void
    {
        $this->listener(['sync', 'async'])->sample();

        self::assertCount(2, $this->recorder->calls);
        self::assertSame(['set', BuiltInMetric::MESSENGER_QUEUE_DEPTH, 7, ['async']], $this->recorder->calls[0]);
        self::assertSame(['set', BuiltInMetric::MESSENGER_QUEUE_DEPTH_SAMPLED_AT, $this->clock->now()->getTimestamp(), ['async']], $this->recorder->calls[1]);
        self::assertSame([], $this->logger->records, 'a transport that cannot count is skipped silently when every transport is sampled');
    }

    public function testAListedTransportThatCannotCountIsReportedOncePerProcess(): void
    {
        $cache = new ArrayAdapter(clock: $this->clock);
        $listener = $this->listener(['sync'], $cache, listed: true);

        $listener->sample();

        $this->clock->sleep(61);
        $listener->sample();
        $this->clock->sleep(61);
        $listener->sample();

        self::assertSame(['sync', 'sync', 'sync'], $this->reader->reads);
        self::assertSame(['warning: Messenger transport "sync" cannot count its messages: its queue depth is not sampled.'], $this->logger->records);
    }

    public function testSamplesAtMostOncePerPeriod(): void
    {
        $cache = new ArrayAdapter(clock: $this->clock);
        $listener = $this->listener(['async'], $cache);

        $listener->sample();
        $this->reader->depths['async'] = 9;
        $this->clock->sleep(59);
        $listener->sample();
        self::assertCount(2, $this->recorder->calls);

        $this->clock->sleep(2);
        $listener->sample();

        self::assertCount(4, $this->recorder->calls);
        self::assertSame(['set', BuiltInMetric::MESSENGER_QUEUE_DEPTH, 9, ['async']], $this->recorder->calls[2]);
    }

    /**
     * @return iterable<string, array{int|float|string, float}>
     */
    public static function periodEndProvider(): iterable
    {
        yield 'float end of another process' => [1030.5, 1030.5];
        yield 'integer end' => [1030, 1030.0];
        yield 'unreadable entry: one period from now' => ['garbage', 1120.0];
    }

    #[DataProvider('periodEndProvider')]
    public function testThePoolIsNotAskedAgainBeforeThePeriodEnds(int|float|string $storedEnd, float $notBefore): void
    {
        $time = 1000.0;
        $reads = 0;
        $cache = self::createStub(CacheInterface::class);
        $cache->method('get')->willReturnCallback(static function (string $key, callable $callback) use (&$reads, $storedEnd): mixed {
            $reads++;
            $save = true;

            return $reads === 1 ? $callback((new ArrayAdapter())->getItem($key), $save) : $storedEnd;
        });
        $listener = $this->listener(['async'], $cache, now: static function () use (&$time): float {
            return $time;
        });

        $listener->sample();

        $time = 1059.9;
        $listener->sample();
        self::assertSame(1, $reads, 'this process sampled the period that ends at 1060');

        $time = 1060.0;
        $listener->sample();
        self::assertSame(2, $reads, 'the period ended: ask the pool again');

        $time = $notBefore - 0.5;
        $listener->sample();
        self::assertSame(2, $reads, 'another process owns the next period: wait for its end');

        $time = $notBefore;
        $listener->sample();
        self::assertSame(3, $reads);
        self::assertSame([], $this->logger->records);
        self::assertCount(2, $this->recorder->calls);
    }

    public function testTheSampleExpiresWithThePeriod(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::once())->method('get')->willReturnCallback(function (string $key, callable $callback): mixed {
            $item = $this->createMock(CacheItemInterface::class);
            $item->expects(self::once())->method('expiresAfter')->with(self::callback(static fn (mixed $interval): bool => $interval instanceof DateInterval && $interval->i === 1));
            $save = true;

            return $callback($item, $save);
        });

        $this->listener(['async'], $cache)->sample();

        self::assertCount(2, $this->recorder->calls);
    }

    public function testAFailingTransportIsLoggedAndSkipped(): void
    {
        $this->listener(['down', 'other'])->sample();

        self::assertSame(['set', BuiltInMetric::MESSENGER_QUEUE_DEPTH, 3, ['other']], $this->recorder->calls[0]);
        self::assertCount(2, $this->recorder->calls);
        self::assertSame(['warning: Cannot sample the queue depth of Messenger transport "down": broker unreachable'], $this->logger->records);
        self::assertInstanceOf(RuntimeException::class, $this->logger->contexts[0]['exception'] ?? null);
    }

    public function testAFailingThrottlePoolIsNotAskedAgainBeforeAPeriodPassed(): void
    {
        $time = 1000.0;
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::exactly(2))->method('get')->willThrowException(new RuntimeException('cache down'));
        $listener = $this->listener(['async'], $cache, now: static function () use (&$time): float {
            return $time;
        });

        $listener->sample();

        $time = 1059.9;
        $listener->sample();
        self::assertCount(1, $this->logger->records, 'one warning per period, not one per worker loop');

        $time = 1060.0;
        $listener->sample();
        self::assertCount(2, $this->logger->records);
        self::assertSame([], $this->recorder->calls);
    }

    public function testAFailingThrottlePoolSkipsTheSample(): void
    {
        $cache = self::createStub(CacheInterface::class);
        $cache->method('get')->willThrowException(new RuntimeException('cache down'));

        $this->listener(['async'], $cache)->sample();

        self::assertSame([], $this->recorder->calls);
        self::assertSame(['warning: Cannot sample the Messenger queue depth: cache down'], $this->logger->records);
        self::assertInstanceOf(RuntimeException::class, $this->logger->contexts[0]['exception'] ?? null);
    }

    /**
     * @param list<string> $transports
     * @param (Closure(): float)|null $now
     */
    private function listener(array $transports, ?CacheInterface $cache = null, bool $listed = false, ?Closure $now = null): MessengerQueueDepthListener
    {
        return new MessengerQueueDepthListener(
            new MessengerCollector($this->recorder),
            $this->reader,
            $transports,
            $listed,
            $cache ?? new ArrayAdapter(),
            $this->logger,
            'PT1M',
            $now ?? fn (): float => (float) $this->clock->now()->format('U.u'),
        );
    }
}
