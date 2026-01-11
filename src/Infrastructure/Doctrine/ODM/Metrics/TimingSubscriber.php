<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Doctrine\ODM\Metrics;

use MaxShamaev\MetricsBundle\Infrastructure\Collector\MongoDbCollector;
use MongoDB\Driver\Monitoring\CommandFailedEvent;
use MongoDB\Driver\Monitoring\CommandStartedEvent;
use MongoDB\Driver\Monitoring\CommandSubscriber;
use MongoDB\Driver\Monitoring\CommandSucceededEvent;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(tags: ['container.service_initializer'], public: true)]
class TimingSubscriber implements CommandSubscriber
{
    public function __construct(
        private readonly MongoDbCollector $collector,
    ) {
    }

    public function commandStarted(CommandStartedEvent $event): void
    {
    }

    public function commandSucceeded(CommandSucceededEvent $event): void
    {
        $host = method_exists($event, 'getHost') ? $event->getHost() : 'unknown';

        $this->collector->incCommandSuccess($event->getCommandName(), $host);
        $this->collector->setCommandDuration($event->getCommandName(), $host, $event->getDurationMicros() / 1000000);
    }

    public function commandFailed(CommandFailedEvent $event): void
    {
        $host = method_exists($event, 'getHost') ? $event->getHost() : 'unknown';

        $this->collector->incCommandFailed($event->getCommandName(), $host);
    }
}
