<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Framework\EventListener;

use MaxShamaev\MetricsBundle\Infrastructure\Collector\ErrorCollector;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

#[AsEventListener(event: 'kernel.exception', method: 'onException', priority: 4096)]
class ExceptionEventListener
{
    public function __construct(
        private readonly ErrorCollector $collector,
    ) {
    }

    public function onException(ExceptionEvent $event): void
    {
        $this->collector->incException($event->getThrowable());
    }
}
