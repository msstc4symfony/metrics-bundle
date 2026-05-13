<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Test\Integration\Infrastructure\Doctrine\ODM\Metrics;

use MaxShamaev\MetricsBundle\Infrastructure\Collector\MongoDbCollector;
use MaxShamaev\MetricsBundle\Infrastructure\Doctrine\ODM\Metrics\TimingSubscriber;
use MaxShamaev\MetricsBundle\Infrastructure\Repository\MetricRepository;
use MongoDB\Driver\Monitoring\CommandSubscriber;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;

/**
 * Note: MongoDB\Driver\Monitoring\Command*Event classes from ext-mongodb are declared `final`
 * and cannot be instantiated outside the driver. A full behavioural test of TimingSubscriber
 * requires a live MongoDB connection (out of scope for unit/integration test phases).
 *
 * This test only verifies the subscriber wires up correctly and implements the required interface.
 */
final class TimingSubscriberTest extends TestCase
{
    protected function setUp(): void
    {
        if (!interface_exists(CommandSubscriber::class)) {
            self::markTestSkipped('ext-mongodb not installed');
        }
    }

    public function testSubscriberImplementsCommandSubscriberInterface(): void
    {
        $collector = new MongoDbCollector(new CollectorRegistry(new InMemory()), new MetricRepository([]), 'app', 'cmp');

        self::assertInstanceOf(CommandSubscriber::class, new TimingSubscriber($collector));
    }
}
