<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\Messenger;

enum TestMessageOutcome
{
    case HANDLED;
    case RECOVERABLE_FAILURE;
    case UNRECOVERABLE_FAILURE;
}
