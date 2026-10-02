<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Support\Redis;

use Closure;
use Throwable;

final class FakeRedisServer
{
    public bool $up = true;

    /** Thrown once by the next command, as if the client library itself had failed. */
    public ?Throwable $nextError = null;

    /** Raised as a PHP warning by the command that finds the server down, as phpredis does on connect. */
    public ?string $warningWhenDown = null;

    /** Raised as E_USER_DEPRECATED by every command, to check it reaches the application handler. */
    public ?string $deprecation = null;

    /** Raised as E_USER_NOTICE by every command that succeeds. */
    public ?string $notice = null;

    /** @var (Closure(): void)|null called before every command is served */
    public ?Closure $onCommand = null;

    /** @var list<string> */
    public array $commands = [];
}
