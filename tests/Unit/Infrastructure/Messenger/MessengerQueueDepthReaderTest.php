<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Infrastructure\Messenger;

use Msstc4Symfony\MetricsBundle\Infrastructure\Messenger\MessengerQueueDepthReader;
use Msstc4Symfony\MetricsBundle\Test\Support\Messenger\CountableReceiver;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;

#[RequiresMethod(MessageCountAwareInterface::class, 'getMessageCount')]
final class MessengerQueueDepthReaderTest extends TestCase
{
    public function testReadsTheCountOfACountableReceiver(): void
    {
        self::assertSame(4, $this->reader()->messageCount('countable'));
    }

    public function testANegativeCountOfABrokerIsZero(): void
    {
        self::assertSame(0, (new MessengerQueueDepthReader(new ServiceLocator(['odd' => static fn (): CountableReceiver => new CountableReceiver(-3)])))->messageCount('odd'));
    }

    public function testAReceiverThatCannotCountHasNoDepth(): void
    {
        self::assertNull($this->reader()->messageCount('memory'));
    }

    public function testFailuresReachTheCaller(): void
    {
        $this->expectExceptionMessage('broker unreachable');

        $this->reader()->messageCount('down');
    }

    public function testAReceiverThatCannotBeCreatedFails(): void
    {
        $this->expectExceptionMessage('DSN rejected');

        $this->reader()->messageCount('broken');
    }

    private function reader(): MessengerQueueDepthReader
    {
        return new MessengerQueueDepthReader(new ServiceLocator([
            'countable' => static fn (): CountableReceiver => new CountableReceiver(4),
            'memory' => static fn (): InMemoryTransport => new InMemoryTransport(),
            'down' => static fn (): CountableReceiver => new CountableReceiver(null),
            'broken' => static fn (): never => throw new RuntimeException('DSN rejected'),
        ]));
    }
}
