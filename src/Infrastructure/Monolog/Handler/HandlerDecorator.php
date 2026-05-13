<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Monolog\Handler;

use MaxShamaev\MetricsBundle\Infrastructure\Collector\ErrorCollector;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Stringable;
use Throwable;

final class HandlerDecorator implements LoggerInterface
{
    private const DEFAULT_ALLOWED_LEVELS = [
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
    private readonly array $allowedLevelMap;

    /**
     * @param Level[] $allowedLevels
     */
    public function __construct(
        private readonly LoggerInterface $inner,
        private readonly ErrorCollector $collector,
        array $allowedLevels = self::DEFAULT_ALLOWED_LEVELS,
    ) {
        $map = [];
        foreach ($allowedLevels as $level) {
            $map[$level->value] = true;
        }
        $this->allowedLevelMap = $map;
    }

    public function emergency(Stringable|string $message, array $context = []): void
    {
        $this->record(Level::Emergency);

        $this->inner->emergency($message, $context);
    }

    public function alert(Stringable|string $message, array $context = []): void
    {
        $this->record(Level::Alert);

        $this->inner->alert($message, $context);
    }

    public function critical(Stringable|string $message, array $context = []): void
    {
        $this->record(Level::Critical);

        $this->inner->critical($message, $context);
    }

    public function error(Stringable|string $message, array $context = []): void
    {
        $this->record(Level::Error);

        $this->inner->error($message, $context);
    }

    public function warning(Stringable|string $message, array $context = []): void
    {
        $this->record(Level::Warning);

        $this->inner->warning($message, $context);
    }

    public function notice(Stringable|string $message, array $context = []): void
    {
        $this->record(Level::Notice);

        $this->inner->notice($message, $context);
    }

    public function info(Stringable|string $message, array $context = []): void
    {
        $this->record(Level::Info);

        $this->inner->info($message, $context);
    }

    public function debug(Stringable|string $message, array $context = []): void
    {
        $this->record(Level::Debug);

        $this->inner->debug($message, $context);
    }

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
