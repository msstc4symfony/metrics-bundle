<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\DependencyInjection;

use Msstc4Symfony\MetricsBundle\DependencyInjection\CompilerPass\AliasThrottleCachePass;
use Msstc4Symfony\MetricsBundle\Presentation\EventListener\MessengerQueueDepthListener;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\Messenger\QueueDepthKernel;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\TestKernel;
use Override;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;

/**
 * symfony/dependency-injection 8.1 deprecates matching a named autowiring alias by the parameter name alone.
 */
final class MetricsCacheAutowiringTest extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        if (!class_exists(MonologBundle::class) || !class_exists(WorkerRunningEvent::class)) {
            self::markTestSkipped('symfony/monolog-bundle and symfony/messenger required');
        }

        (new Filesystem())->remove(TestKernel::cacheRoot());
    }

    #[Override]
    protected function tearDown(): void
    {
        (new Filesystem())->remove(TestKernel::cacheRoot());
    }

    public function testTheMetricsCacheIsNotAutowiredByParameterName(): void
    {
        $deprecations = [];
        set_error_handler(static function (int $type, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, \E_USER_DEPRECATED);

        try {
            $kernel = new QueueDepthKernel(['transports' => [QueueDepthKernel::COUNTABLE_TRANSPORT]]);
            $kernel->boot();
            $kernel->shutdown();
        } finally {
            restore_error_handler();
        }

        self::assertSame([], array_values(array_filter($deprecations, static fn (string $message): bool => str_contains($message, '"$metricsCache"'))));
    }

    public function testWithoutAMetricsCachePoolTheQueueDepthListenerReadsTheAppPool(): void
    {
        $kernel = new QueueDepthKernel(['transports' => [QueueDepthKernel::COUNTABLE_TRANSPORT]], metricsPool: false);
        $kernel->boot();

        try {
            $container = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $container);
            self::assertSame($container->get('cache.app'), $container->get(AliasThrottleCachePass::THROTTLE_CACHE));
            self::assertInstanceOf(MessengerQueueDepthListener::class, $container->get(MessengerQueueDepthListener::class));
        } finally {
            $kernel->shutdown();
        }
    }
}
