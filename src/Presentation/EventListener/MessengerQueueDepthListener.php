<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Presentation\EventListener;

use Closure;
use DateInterval;
use DateTimeImmutable;
use Msstc4Symfony\MetricsBundle\Application\Collector\MessengerCollector;
use Msstc4Symfony\MetricsBundle\Application\Port\QueueDepthReaderInterface;
use Psr\Cache\CacheItemInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Throwable;

/**
 * Samples the number of waiting messages of the Messenger transports at most once per period, across every process
 * that shares the metrics.cache pool (as InfoEventListener does). The shared entry holds the end of the current
 * period, so a process asks the pool again only once that time has passed: an idle worker loops about once a second.
 * Sampling runs on kernel.terminate and in workers on WorkerRunningEvent, so it never throws: a transport that fails
 * to count is logged and keeps its last sample.
 *
 * Registered by RegisterMessengerQueueDepthPass only.
 *
 * @internal
 */
final readonly class MessengerQueueDepthListener
{
    private const THROTTLE_KEY = 'metrics_messenger_queue_depth_period_end';

    private DateInterval $period;

    private int $periodSeconds;

    /** @var Closure(): float */
    private Closure $now;

    private QueueDepthSamplingState $state;

    /**
     * @param list<string> $transports
     * @param bool $listed whether the transports were listed in the configuration: one that cannot count is reported
     * @param (Closure(): float)|null $now current Unix time with microseconds
     */
    public function __construct(
        private MessengerCollector $collector,
        private QueueDepthReaderInterface $reader,
        private array $transports,
        private bool $listed,
        private CacheInterface $metricsCache,
        private LoggerInterface $logger,
        string $period,
        ?Closure $now = null,
    ) {
        $this->period = new DateInterval($period);
        $epoch = new DateTimeImmutable('@0');
        $this->periodSeconds = $epoch->add($this->period)->getTimestamp();
        $this->now = $now ?? static fn (): float => microtime(true);
        $this->state = new QueueDepthSamplingState();
    }

    public function sample(): void
    {
        $now = ($this->now)();
        if ($this->state->isThrottled($now)) {
            return;
        }

        try {
            if (!$this->claimPeriod($now)) {
                return;
            }
        } catch (Throwable $exception) {
            // A full period: during a cache outage a worker would otherwise retry and log on every loop.
            $this->state->throttleUntil($now + $this->periodSeconds);
            $this->logger->warning('Cannot sample the Messenger queue depth: ' . $exception->getMessage(), ['exception' => $exception]);

            return;
        }

        foreach ($this->transports as $transport) {
            $this->sampleTransport($transport, (int) $now);
        }
    }

    private function sampleTransport(string $transport, int $now): void
    {
        try {
            $messages = $this->reader->messageCount($transport);
        } catch (Throwable $exception) {
            $this->logger->warning(\sprintf('Cannot sample the queue depth of Messenger transport "%s": %s', $transport, $exception->getMessage()), ['exception' => $exception]);

            return;
        }

        if ($messages === null) {
            if ($this->listed && $this->state->markUncountableOnce($transport)) {
                $this->logger->warning(\sprintf('Messenger transport "%s" cannot count its messages: its queue depth is not sampled.', $transport));
            }

            return;
        }

        $this->collector->setQueueDepth($transport, $messages);
        $this->collector->setQueueDepthSampledAt($transport, $now);
    }

    /**
     * Whether this process owns the period starting now; either way, remembers when the current period ends.
     */
    private function claimPeriod(float $now): bool
    {
        $claimed = false;
        $periodEnd = $this->metricsCache->get(self::THROTTLE_KEY, function (CacheItemInterface $item) use ($now, &$claimed): float {
            $claimed = true;
            $item->expiresAfter($this->period);

            return $now + $this->periodSeconds;
        });

        $this->state->throttleUntil(\is_float($periodEnd) || \is_int($periodEnd) ? $periodEnd : $now + $this->periodSeconds);

        return $claimed;
    }
}
