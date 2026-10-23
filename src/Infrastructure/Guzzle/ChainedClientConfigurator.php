<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Guzzle;

use Closure;
use GuzzleHttp\ClientInterface;

/**
 * Runs a client's own service configurator, then adds the metrics middleware: a service has a single configurator
 * slot, which `AddGuzzleMonitorPass` must not take away from the application.
 *
 * @internal
 */
final readonly class ChainedClientConfigurator
{
    /** @var Closure(ClientInterface): void */
    private Closure $configurator;

    /**
     * @param callable(ClientInterface): void $configurator a Symfony service configurator; its result is ignored
     */
    public function __construct(
        callable $configurator,
        private MetricsGuzzleMiddleware $metrics,
    ) {
        $this->configurator = $configurator(...);
    }

    public function __invoke(ClientInterface $client): void
    {
        ($this->configurator)($client);
        $this->metrics->addHandler($client);
    }
}
