<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Framework\EventListener;

use Msstc4Symfony\MetricsBundle\Framework\EventListener\RequestEventListener;
use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\RequestCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepository;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Sample;
use Prometheus\Storage\InMemory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class RequestEventListenerTest extends TestCase
{
    private CollectorRegistry $registry;

    private RequestCollector $collector;

    private HttpKernelInterface $kernel;

    protected function setUp(): void
    {
        if (!class_exists(RequestEvent::class)) {
            self::markTestSkipped('symfony/http-kernel not installed');
        }

        $this->registry = new CollectorRegistry(new InMemory());
        $this->collector = new RequestCollector($this->registry, new MetricRepository([]), 'app', 'cmp');
        $this->kernel = self::createStub(HttpKernelInterface::class);
    }

    public function testMainRequestFlowRecordsAllMetrics(): void
    {
        $listener = new RequestEventListener($this->collector);

        $request = Request::create('/orders/42', Request::METHOD_GET);
        $request->attributes->set('_route', 'order_show');

        $listener->onRequestFirst($this->requestEvent($request));
        $listener->onRequest($this->requestEvent($request));
        $listener->onTerminate(new TerminateEvent($this->kernel, $request, new Response('ok', Response::HTTP_CREATED)));

        self::assertSame(
            [['app', 'cmp', 'GET', 'order_show']],
            $this->labelValuesFor('symfony_' . MetricLabelEnum::HTTP_REQUEST->value),
        );
        self::assertSame(
            [['app', 'cmp', 'GET', 'order_show', '201']],
            $this->labelValuesFor('symfony_' . MetricLabelEnum::HTTP_RESPONSE->value),
        );
        self::assertTrue($this->familyExists('symfony_' . MetricLabelEnum::REQUEST_DURATION_HISTOGRAM_SECONDS->value));
        self::assertTrue($this->familyExists('symfony_' . MetricLabelEnum::REQUEST_DURATION_SUMMARY_SECONDS->value));
    }

    public function testSubRequestIsIgnored(): void
    {
        $listener = new RequestEventListener($this->collector);

        $request = Request::create('/sub', Request::METHOD_GET);
        $listener->onRequestFirst($this->requestEvent($request, isMain: false));
        $listener->onRequest($this->requestEvent($request, isMain: false));

        self::assertFalse($this->familyExists('symfony_' . MetricLabelEnum::HTTP_REQUEST->value));
    }

    public function testOptionsRequestIsIgnored(): void
    {
        $listener = new RequestEventListener($this->collector);

        $request = Request::create('/anything', Request::METHOD_OPTIONS);
        $listener->onRequestFirst($this->requestEvent($request));
        $listener->onRequest($this->requestEvent($request));

        self::assertFalse($this->familyExists('symfony_' . MetricLabelEnum::HTTP_REQUEST->value));
    }

    public function testIgnoredRouteResetsStartedAt(): void
    {
        $listener = new RequestEventListener($this->collector);

        $request = Request::create('/_/metrics', Request::METHOD_GET);
        $request->attributes->set('_route', 'metrics-get');

        $listener->onRequestFirst($this->requestEvent($request));
        $listener->onRequest($this->requestEvent($request));
        $listener->onTerminate(new TerminateEvent($this->kernel, $request, new Response('', Response::HTTP_OK)));

        self::assertFalse($this->familyExists('symfony_' . MetricLabelEnum::HTTP_REQUEST->value));
        self::assertFalse($this->familyExists('symfony_' . MetricLabelEnum::HTTP_RESPONSE->value));
    }

    private function requestEvent(Request $request, bool $isMain = true): RequestEvent
    {
        return new RequestEvent(
            $this->kernel,
            $request,
            $isMain ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::SUB_REQUEST,
        );
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
