<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Monolog\Handler;

use MaxShamaev\MetricsBundle\Infrastructure\Collector\ErrorCollector;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Stringable;
use Throwable;

class HandlerDecorator implements LoggerInterface
{
    /**
     * @param Level[] $allowedLevels
     */
    public function __construct(
        private readonly LoggerInterface $inner,
        private readonly ErrorCollector $collector,
        private readonly array $allowedLevels = [Level::Emergency, Level::Alert, Level::Critical, Level::Error, Level::Warning, Level::Notice],
    ) {
    }

    public function emergency(Stringable|string $message, array $context = []): void
    {
        $this->collector->incError(Level::Emergency);

        $this->inner->emergency($message, $context);
    }

    public function alert(Stringable|string $message, array $context = []): void
    {
        $this->collector->incError(Level::Alert);

        $this->inner->alert($message, $context);
    }

    public function critical(Stringable|string $message, array $context = []): void
    {
        $this->collector->incError(Level::Critical);

        $this->inner->critical($message, $context);
    }

    public function error(Stringable|string $message, array $context = []): void
    {
        $this->collector->incError(Level::Error);

        $this->inner->error($message, $context);
    }

    public function warning(Stringable|string $message, array $context = []): void
    {
        $this->collector->incError(Level::Warning);

        $this->inner->warning($message, $context);
    }

    public function notice(Stringable|string $message, array $context = []): void
    {
        $this->collector->incError(Level::Notice);

        $this->inner->notice($message, $context);
    }

    public function info(Stringable|string $message, array $context = []): void
    {
        $this->inner->info($message, $context);
    }

    public function debug(Stringable|string $message, array $context = []): void
    {
        $this->inner->debug($message, $context);
    }

    public function log($level, Stringable|string $message, array $context = []): void
    {
        if (!($level instanceof Level)) {
            try {
                $level = Logger::toMonologLevel($level);
            } catch (Throwable) {
            }
        }

        if ($level instanceof Level && in_array($level, $this->allowedLevels, true)) {
            $this->collector->incError($level);
        }

        $this->inner->log($level, $message, $context);
    }
}
