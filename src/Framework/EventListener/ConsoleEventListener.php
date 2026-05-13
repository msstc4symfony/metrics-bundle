<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Framework\EventListener;

use MaxShamaev\MetricsBundle\Infrastructure\Collector\ConsoleCollector;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: ConsoleEvents::COMMAND, method: 'onCommand', priority: 4096)]
#[AsEventListener(event: ConsoleEvents::TERMINATE, method: 'onTerminate')]
final class ConsoleEventListener
{
    private ?float $startedAt = null;

    public function __construct(
        private readonly ConsoleCollector $collector,
    ) {
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        $this->startedAt = null;
        if ($event->getCommand()?->getName() === null) {
            return;
        }
        $this->startedAt = microtime(true);
        $this->collector->incConsoleCommandStart($event->getCommand()->getName());
    }

    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        if ($this->startedAt === null || $event->getCommand()?->getName() === null) {
            return;
        }

        $this->collector->incConsoleCommandFinish($event->getCommand()->getName());
        $this->collector->setCommandDuration($event->getCommand()->getName(), microtime(true) - $this->startedAt);
    }
}
