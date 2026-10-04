<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Infrastructure\Elastica;

use Elastica\Request;
use Elastica\Response;
use Elastica\Transport\AbstractTransport;
use LogicException;
use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\ElasticaCollector;
use Msstc4Symfony\MetricsBundle\Infrastructure\Elastica\TimingTransport;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Repository\MetricRepository;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;
use RuntimeException;

final class TimingTransportTest extends TestCase
{
    private CollectorRegistry $registry;

    private ElasticaCollector $collector;

    protected function setUp(): void
    {
        if (!class_exists(AbstractTransport::class)) {
            self::markTestSkipped('ruflin/elastica not installed');
        }

        $this->registry = new CollectorRegistry(new InMemory());
        $this->collector = new ElasticaCollector($this->registry, new MetricRepository([]), 'app', 'cmp');
    }

    public function testSuccessfulRequestRecordsCountAndDuration(): void
    {
        $request = new Request('/index/_search', Request::POST);

        $response = self::createStub(Response::class);
        $response->method('getQueryTime')->willReturn(0.05);

        $inner = $this->createMock(AbstractTransport::class);
        $inner->expects(self::once())->method('exec')->willReturn($response);

        $transport = new TimingTransport()->init($inner, $this->collector);
        $transport->exec($request, []);

        self::assertSame(
            [['app', 'cmp', 'POST', '/index/_search']],
            RegistrySamples::labels($this->registry, MetricLabelEnum::ELASTICA_REQUEST_SUCCESS),
        );
        self::assertTrue(RegistrySamples::exists($this->registry, MetricLabelEnum::ELASTICA_REQUEST_DURATION_HISTOGRAM_SECONDS));
    }

    public function testFailedRequestRecordsFailureAndRethrows(): void
    {
        $request = new Request('/index/_search', Request::POST);

        $inner = self::createStub(AbstractTransport::class);
        $inner->method('exec')->willThrowException(new RuntimeException('upstream gone'));

        $transport = new TimingTransport()->init($inner, $this->collector);

        $caught = null;
        try {
            $transport->exec($request, []);
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught);
        self::assertSame(
            [['app', 'cmp', 'POST', '/index/_search']],
            RegistrySamples::labels($this->registry, MetricLabelEnum::ELASTICA_REQUEST_FAILED),
        );
    }

    public function testExecBeforeInitThrowsLogicException(): void
    {
        $this->expectException(LogicException::class);
        new TimingTransport()->exec(new Request('/', Request::GET), []);
    }
}
