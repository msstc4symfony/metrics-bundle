<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\Messenger;

final readonly class TestMessage
{
    public function __construct(
        public TestMessageOutcome $outcome = TestMessageOutcome::HANDLED,
    ) {
    }
}
