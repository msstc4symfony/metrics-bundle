<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Framework\EventListener;

use Msstc4Symfony\MetricsBundle\Framework\EventListener\ConsoleEventListener;
use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\ConsoleCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepository;
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

    protected function setUp(): void
    {
        if (!class_exists(ConsoleCommandEvent::class)) {
            self::markTestSkipped('symfony/console not installed');
        }

        $this->registry = new CollectorRegistry(new InMemory());
        $this->collector = new ConsoleCollector($this->registry, new MetricRepository([]), 'app', 'cmp');
    }

    public function testCommandLifecycleRecordsStartFinishAndDuration(): void
    {
        $listener = new ConsoleEventListener($this->collector);

        $command = new Command('app:run');
        $input = self::createStub(InputInterface::class);
        $output = self::createStub(OutputInterface::class);

        $listener->onCommand(new ConsoleCommandEvent($command, $input, $output));
        $listener->onTerminate(new ConsoleTerminateEvent($command, $input, $output, 0));

        self::assertTrue($this->familyExists('symfony_' . MetricLabelEnum::CONSOLE_COMMAND_START->value));
        self::assertTrue($this->familyExists('symfony_' . MetricLabelEnum::CONSOLE_COMMAND_FINISH->value));
        self::assertTrue($this->familyExists('symfony_' . MetricLabelEnum::CONSOLE_COMMAND_DURATION_HISTOGRAM_SECONDS->value));
    }

    public function testEventWithoutCommandIsIgnored(): void
    {
        $listener = new ConsoleEventListener($this->collector);

        $input = self::createStub(InputInterface::class);
        $output = self::createStub(OutputInterface::class);

        $listener->onCommand(new ConsoleCommandEvent(null, $input, $output));

        self::assertFalse($this->familyExists('symfony_' . MetricLabelEnum::CONSOLE_COMMAND_START->value));
    }

    public function testTerminateWithoutPriorStartIsIgnored(): void
    {
        $listener = new ConsoleEventListener($this->collector);

        $command = new Command('app:run');
        $input = self::createStub(InputInterface::class);
        $output = self::createStub(OutputInterface::class);

        $listener->onTerminate(new ConsoleTerminateEvent($command, $input, $output, 0));

        self::assertFalse($this->familyExists('symfony_' . MetricLabelEnum::CONSOLE_COMMAND_FINISH->value));
    }

    private function familyExists(string $name): bool
    {
        return array_any($this->registry->getMetricFamilySamples(), fn ($family): bool => $family->getName() === $name);
    }
}
