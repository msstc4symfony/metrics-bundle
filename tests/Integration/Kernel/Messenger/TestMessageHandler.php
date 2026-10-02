<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\Messenger;

use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
final class TestMessageHandler
{
    public function __invoke(TestMessage $message): void
    {
        match ($message->outcome) {
            TestMessageOutcome::HANDLED => null,
            TestMessageOutcome::RECOVERABLE_FAILURE => throw new RuntimeException('Recoverable failure'),
            TestMessageOutcome::UNRECOVERABLE_FAILURE => throw new UnrecoverableMessageHandlingException('Unrecoverable failure'),
        };
    }
}
