<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Test\Integration\Framework\EventListener;

use MaxShamaev\MetricsBundle\Framework\EventListener\ExceptionEventListener;
use MaxShamaev\MetricsBundle\Infrastructure\Collector\ErrorCollector;
use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use MaxShamaev\MetricsBundle\Infrastructure\Repository\MetricRepository;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Sample;
use Prometheus\Storage\InMemory;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ExceptionEventListenerTest extends TestCase
{
    private CollectorRegistry $registry;

    private ErrorCollector $collector;

    protected function setUp(): void
    {
        if (!class_exists(ExceptionEvent::class)) {
            self::markTestSkipped('symfony/http-kernel not installed');
        }

        $this->registry = new CollectorRegistry(new InMemory());
        $this->collector = new ErrorCollector($this->registry, new MetricRepository([]), 'app', 'cmp');
    }

    public function testIncrementsExceptionCounter(): void
    {
        $listener = new ExceptionEventListener($this->collector);
        $kernel = self::createStub(HttpKernelInterface::class);

        $listener->onException(new ExceptionEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST, new RuntimeException('boom')));

        self::assertSame(
            [['app', 'cmp', RuntimeException::class]],
            $this->labelValuesFor('symfony_' . MetricLabelEnum::EXCEPTION->value),
        );
    }

    public function testIgnoredExceptionsAreNotRecorded(): void
    {
        $listener = new ExceptionEventListener($this->collector);
        $kernel = self::createStub(HttpKernelInterface::class);

        $listener->onException(new ExceptionEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST, new NotFoundHttpException()));

        self::assertFalse($this->familyExists('symfony_' . MetricLabelEnum::EXCEPTION->value));
    }

    /**
     * @return array<array<int, string>>
     */
    private function labelValuesFor(string $name): array
    {
        foreach ($this->registry->getMetricFamilySamples() as $family) {
            if ($family->getName() !== $name) {
                continue;
            }

            return array_map(
                static fn (Sample $sample): array => $sample->getLabelValues(),
                $family->getSamples(),
            );
        }

        return [];
    }

    private function familyExists(string $name): bool
    {
        return array_any($this->registry->getMetricFamilySamples(), fn ($family): bool => $family->getName() === $name);
    }
}
