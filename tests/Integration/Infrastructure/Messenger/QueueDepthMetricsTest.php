<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Infrastructure\Messenger;

use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Presentation\EventListener\MessengerQueueDepthListener;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\Messenger\QueueDepthKernel;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\TestKernel;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;
use Override;
use PHPUnit\Framework\TestCase;
use Prometheus\RegistryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Worker;

/**
 * @phpstan-import-type QueueDepthConfigShape from QueueDepthKernel
 */
final class QueueDepthMetricsTest extends TestCase
{
    private ?QueueDepthKernel $kernel = null;

    #[Override]
    protected function setUp(): void
    {
        if (!class_exists(WorkerRunningEvent::class)) {
            self::markTestSkipped('symfony/messenger not installed');
        }

        (new Filesystem())->remove(TestKernel::cacheRoot());
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        (new Filesystem())->remove(TestKernel::cacheRoot());
    }

    public function testDisabledByDefault(): void
    {
        $this->boot(null);

        self::assertFalse($this->container()->has(MessengerQueueDepthListener::class));
    }

    public function testTheCountableTransportsAreSampledWhenARequestTerminates(): void
    {
        $kernel = $this->boot(['enabled' => true]);

        $request = Request::create('/_/metrics');
        $kernel->terminate($request, $kernel->handle($request));

        $registry = $this->registry();
        self::assertSame([[['unknown', 'unknown', QueueDepthKernel::COUNTABLE_TRANSPORT], '5']], RegistrySamples::samples($registry, BuiltInMetric::MESSENGER_QUEUE_DEPTH));
        self::assertSame([['unknown', 'unknown', QueueDepthKernel::COUNTABLE_TRANSPORT]], RegistrySamples::labels($registry, BuiltInMetric::MESSENGER_QUEUE_DEPTH_SAMPLED_AT));
    }

    public function testAWorkerLoopSamplesToo(): void
    {
        $this->boot(['transports' => [QueueDepthKernel::COUNTABLE_TRANSPORT]]);
        $container = $this->container();
        $dispatcher = $container->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $dispatcher->dispatch(new WorkerRunningEvent(new Worker([], self::createStub(MessageBusInterface::class)), true));

        self::assertCount(1, RegistrySamples::samples($this->registry(), BuiltInMetric::MESSENGER_QUEUE_DEPTH));
    }

    public function testAnUnknownTransportFailsTheBoot(): void
    {
        $this->expectExceptionMessage('Messenger transport "missing" listed in msstc4symfony_metrics.messenger.queue_depth.transports does not exist; transports: "countable", "async".');

        $this->boot(['transports' => ['missing']]);
    }

    /**
     * @param QueueDepthConfigShape|null $config
     */
    private function boot(?array $config): QueueDepthKernel
    {
        $this->kernel = new QueueDepthKernel($config);
        $this->kernel->boot();

        return $this->kernel;
    }

    private function container(): ContainerInterface
    {
        self::assertNotNull($this->kernel);
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    private function registry(): RegistryInterface
    {
        $registry = $this->container()->get(RegistryInterface::class);
        self::assertInstanceOf(RegistryInterface::class, $registry);

        return $registry;
    }
}
