<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Infrastructure\Cache;

use Closure;
use Msstc4Symfony\MetricsBundle\Infrastructure\Cache\ComputationCounter;
use Msstc4Symfony\MetricsBundle\Infrastructure\Cache\CountingCallbackWrapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\Cache\ItemInterface;

final class CountingCallbackWrapperTest extends TestCase
{
    public function testCountsEachComputationAndForwardsEveryArgument(): void
    {
        $pool = new ArrayAdapter();
        $item = $pool->getItem('a');
        $setMetadata = static function (): void {
        };
        $logger = new NullLogger();
        $forwarded = [];
        $inner = static function (callable $callback, ItemInterface $item, bool &$save, AdapterInterface $pool, Closure $setMetadata, ?LoggerInterface $logger, ?float $beta) use (&$forwarded): mixed {
            $forwarded = [$item, $pool, $setMetadata, $logger, $beta];
            $save = false;

            return $callback($item, $save);
        };
        $counter = new ComputationCounter();
        $wrapper = new CountingCallbackWrapper($inner, $counter);
        self::assertSame(0, $counter->count());

        $save = true;
        $value = $wrapper->__invoke(static fn (ItemInterface $item): string => $item->getKey(), $item, $save, $pool, $setMetadata, $logger, 1.5);

        self::assertSame('a', $value);
        self::assertSame(1, $counter->count());
        self::assertFalse($save, 'the save flag is passed by reference');
        self::assertSame([$item, $pool, $setMetadata, $logger, 1.5], $forwarded);
    }
}
