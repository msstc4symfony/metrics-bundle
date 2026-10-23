<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Support;

use Override;
use Psr\Log\AbstractLogger;
use Stringable;

final class CollectingLogger extends AbstractLogger
{
    /** @var list<string> "level: message" */
    public array $records = [];

    /** @var list<array<array-key, mixed>> context of each record, in the order of */
    public array $contexts = [];

    /**
     * @param array<array-key, mixed> $context
     */
    #[Override]
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = (\is_string($level) ? $level : 'unknown') . ': ' . $message;
        $this->contexts[] = $context;
    }
}
