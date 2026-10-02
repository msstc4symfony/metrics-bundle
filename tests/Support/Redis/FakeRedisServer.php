<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Support\Redis;

use Throwable;

final class FakeRedisServer
{
    public bool $up = true;

    /** Thrown once by the next command, as if the client library itself had failed. */
    public ?Throwable $nextError = null;

    /** @var list<string> */
    public array $commands = [];
}
