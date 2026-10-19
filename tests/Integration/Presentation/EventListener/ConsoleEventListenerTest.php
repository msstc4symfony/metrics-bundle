<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Presentation\EventListener;

use Msstc4Symfony\MetricsBundle\Application\Collector\ConsoleCollector;
use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricRepository;
use Msstc4Symfony\MetricsBundle\Infrastructure\Prometheus\PrometheusMetricRecorder;
use Msstc4Symfony\MetricsBundle\Presentation\EventListener\ConsoleEventListener;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;
use Override;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ConsoleEventListenerTest extends TestCase
{
    private CollectorRegistry $registry;

    private ConsoleCollector $collector;

    #[Override]
    protected function setUp(): void
    {
        if (!class_exists(ConsoleCommandEvent::class)) {
            self::markTestSkipped('symfony/console not installed');
        }

        $this->registry = new CollectorRegistry(new InMemory());
        $this->collector = new ConsoleCollector(new PrometheusMetricRecorder($this->registry, new MetricRepository([]), 'app', 'cmp'));
    }

    public function testCommandLifecycleRecordsStartFinishAndDuration(): void
    {
        $listener = new ConsoleEventListener($this->collector);

        $command = new Command('app:run');
        $input = self::createStub(InputInterface::class);
        $output = self::createStub(OutputInterface::class);

        $listener->onCommand(new ConsoleCommandEvent($command, $input, $output));
        $listener->onTerminate(new ConsoleTerminateEvent($command, $input, $output, 0));

        self::assertTrue(RegistrySamples::exists($this->registry, BuiltInMetric::CONSOLE_COMMAND_START));
        self::assertTrue(RegistrySamples::exists($this->registry, BuiltInMetric::CONSOLE_COMMAND_FINISH));
        self::assertTrue(RegistrySamples::exists($this->registry, BuiltInMetric::CONSOLE_COMMAND_DURATION_HISTOGRAM_SECONDS));
    }

    public function testEventWithoutCommandIsIgnored(): void
    {
        $listener = new ConsoleEventListener($this->collector);

        $input = self::createStub(InputInterface::class);
        $output = self::createStub(OutputInterface::class);

        $listener->onCommand(new ConsoleCommandEvent(null, $input, $output));

        self::assertFalse(RegistrySamples::exists($this->registry, BuiltInMetric::CONSOLE_COMMAND_START));
    }

    public function testTerminateWithoutPriorStartIsIgnored(): void
    {
        $listener = new ConsoleEventListener($this->collector);

        $command = new Command('app:run');
        $input = self::createStub(InputInterface::class);
        $output = self::createStub(OutputInterface::class);

        $listener->onTerminate(new ConsoleTerminateEvent($command, $input, $output, 0));

        self::assertFalse(RegistrySamples::exists($this->registry, BuiltInMetric::CONSOLE_COMMAND_FINISH));
    }
}
