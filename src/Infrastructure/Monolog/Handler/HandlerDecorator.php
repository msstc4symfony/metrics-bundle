<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Monolog\Handler;

use Monolog\Level;
use Monolog\Logger;
use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\ErrorCollector;
use Override;
use Psr\Log\LoggerInterface;
use Stringable;
use Throwable;

final readonly class HandlerDecorator implements LoggerInterface
{
    private const array DEFAULT_ALLOWED_LEVELS = [
        Level::Emergency,
        Level::Alert,
        Level::Critical,
        Level::Error,
        Level::Warning,
        Level::Notice,
    ];

    /**
     * @var array<int, true>
     */
    private array $allowedLevelMap;

    /**
     * @param Level[] $allowedLevels
     */
    public function __construct(
        private LoggerInterface $inner,
        private ErrorCollector $collector,
        array $allowedLevels = self::DEFAULT_ALLOWED_LEVELS,
    ) {
        $map = [];
        foreach ($allowedLevels as $level) {
            $map[$level->value] = true;
        }
        $this->allowedLevelMap = $map;
    }

    #[Override]
    public function emergency(Stringable|string $message, array $context = []): void
    {
        $this->record(Level::Emergency);

        $this->inner->emergency($message, $context);
    }

    #[Override]
    public function alert(Stringable|string $message, array $context = []): void
    {
        $this->record(Level::Alert);

        $this->inner->alert($message, $context);
    }

    #[Override]
    public function critical(Stringable|string $message, array $context = []): void
    {
        $this->record(Level::Critical);

        $this->inner->critical($message, $context);
    }

    #[Override]
    public function error(Stringable|string $message, array $context = []): void
    {
        $this->record(Level::Error);

        $this->inner->error($message, $context);
    }

    #[Override]
    public function warning(Stringable|string $message, array $context = []): void
    {
        $this->record(Level::Warning);

        $this->inner->warning($message, $context);
    }

    #[Override]
    public function notice(Stringable|string $message, array $context = []): void
    {
        $this->record(Level::Notice);

        $this->inner->notice($message, $context);
    }

    #[Override]
    public function info(Stringable|string $message, array $context = []): void
    {
        $this->inner->info($message, $context);
    }

    #[Override]
    public function debug(Stringable|string $message, array $context = []): void
    {
        $this->inner->debug($message, $context);
    }

    #[Override]
    public function log($level, Stringable|string $message, array $context = []): void
    {
        if (!$level instanceof Level) {
            try {
                $level = Logger::toMonologLevel($level);
            } catch (Throwable) {
            }
        }

        if ($level instanceof Level) {
            $this->record($level);
        }

        $this->inner->log($level, $message, $context);
    }

    private function record(Level $level): void
    {
        if (isset($this->allowedLevelMap[$level->value])) {
            $this->collector->incError($level);
        }
    }
}
