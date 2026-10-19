<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Presentation\EventListener;

use Msstc4Symfony\MetricsBundle\Application\Collector\ErrorCollector;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/**
 * @internal
 */
#[AsEventListener(event: 'kernel.exception', method: 'onException', priority: 4096)]
final readonly class ExceptionEventListener
{
    public function __construct(
        private ErrorCollector $collector,
    ) {
    }

    public function onException(ExceptionEvent $event): void
    {
        $this->collector->incException($event->getThrowable());
    }
}
