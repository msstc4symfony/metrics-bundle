<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Test\Unit\Infrastructure\Collector;

use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use MaxShamaev\MetricsBundle\Infrastructure\Repository\MetricRepository;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\MetricFamilySamples;
use Prometheus\RegistryInterface;
use Prometheus\Sample;
use Prometheus\Storage\InMemory;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class AbstractCollectorTest extends TestCase
{
    public function testPrepareLabelValuesPrependsApplicationAndComponent(): void
    {
        $collector = $this->fixture('my-app', 'http');

        self::assertSame(
            ['my-app', 'http', 'GET', '/users'],
            $collector->exposePrepareLabelValues(['GET', '/users']),
        );
    }

    public function testPrepareLabelValuesCastsScalarsToString(): void
    {
        $collector = $this->fixture();

        self::assertSame(
            ['app', 'cmp', '200', '0.5', '1'],
            $collector->exposePrepareLabelValues([200, 0.5, true]),
        );
    }

    public function testIncCounterIncrementsThroughRegistry(): void
    {
        $registry = new CollectorRegistry(new InMemory());
        $collector = $this->fixture(registry: $registry, repository: new MetricRepository([MetricLabelEnum::HTTP_REQUEST]));

        $collector->callIncCounter(MetricLabelEnum::HTTP_REQUEST, ['GET', '/']);

        self::assertContains(
            'symfony_' . MetricLabelEnum::HTTP_REQUEST->value,
            $this->familyNames($registry),
        );
    }

    public function testIncCounterSwallowsThrowableWhenNoLoggerInjected(): void
    {
        $collector = $this->fixture(registry: $this->throwingRegistry());

        $this->expectNotToPerformAssertions();
        $collector->callIncCounter(MetricLabelEnum::HTTP_REQUEST);
    }

    public function testIncCounterReportsThroughInjectedLogger(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(self::stringContains('Cannot save metric'))
        ;

        $collector = $this->fixture(registry: $this->throwingRegistry());
        $collector->setLogger($logger);

        $collector->callIncCounter(MetricLabelEnum::HTTP_REQUEST);
    }

    public function testSetGaugeWritesValueWithPrefixedLabels(): void
    {
        $registry = new CollectorRegistry(new InMemory());
        $collector = $this->fixture(registry: $registry);

        $collector->callSetGauge(MetricLabelEnum::INFO_CPU_LOAD, 0.42);

        self::assertSame(
            [['app', 'cmp']],
            $this->labelValuesFor($registry, 'symfony_' . MetricLabelEnum::INFO_CPU_LOAD->value),
        );
    }

    public function testSetGaugeSwallowsException(): void
    {
        $collector = $this->fixture(registry: $this->throwingRegistry('Gauge'));

        $this->expectNotToPerformAssertions();
        $collector->callSetGauge(MetricLabelEnum::INFO_CPU_LOAD, 1.0);
    }

    public function testObserveHistogramIncludesEnumBatches(): void
    {
        $registry = new CollectorRegistry(new InMemory());
        $collector = $this->fixture(registry: $registry);

        $collector->callObserveHistogram(MetricLabelEnum::REQUEST_DURATION_HISTOGRAM_SECONDS, 0.1, ['GET', '/']);

        self::assertContains(
            'symfony_' . MetricLabelEnum::REQUEST_DURATION_HISTOGRAM_SECONDS->value,
            $this->familyNames($registry),
        );
    }

    public function testObserveHistogramSwallowsException(): void
    {
        $collector = $this->fixture(registry: $this->throwingRegistry('Histogram'));

        $this->expectNotToPerformAssertions();
        $collector->callObserveHistogram(MetricLabelEnum::REQUEST_DURATION_HISTOGRAM_SECONDS, 0.1);
    }

    public function testObserveSummaryRecordsSample(): void
    {
        $registry = new CollectorRegistry(new InMemory());
        $collector = $this->fixture(registry: $registry);

        $collector->callObserveSummary(MetricLabelEnum::REQUEST_DURATION_SUMMARY_SECONDS, 0.5, ['GET', '/']);

        self::assertContains(
            'symfony_' . MetricLabelEnum::REQUEST_DURATION_SUMMARY_SECONDS->value,
            $this->familyNames($registry),
        );
    }

    public function testObserveSummarySwallowsException(): void
    {
        $collector = $this->fixture(registry: $this->throwingRegistry('Summary'));

        $this->expectNotToPerformAssertions();
        $collector->callObserveSummary(MetricLabelEnum::REQUEST_DURATION_SUMMARY_SECONDS, 0.5);
    }

    private function fixture(
        string $applicationName = 'app',
        string $componentName = 'cmp',
        ?RegistryInterface $registry = null,
        ?MetricRepository $repository = null,
    ): TestableAbstractCollector {
        return new TestableAbstractCollector(
            $registry ?? new CollectorRegistry(new InMemory()),
            $repository ?? new MetricRepository([]),
            $applicationName,
            $componentName,
        );
    }

    private function throwingRegistry(string $kind = 'Counter'): RegistryInterface
    {
        $registry = self::createStub(RegistryInterface::class);
        $method = 'getOrRegister' . $kind;
        $registry->method($method)->willThrowException(new RuntimeException('boom'));

        return $registry;
    }

    /**
     * @return string[]
     */
    private function familyNames(CollectorRegistry $registry): array
    {
        return array_map(
            static fn (MetricFamilySamples $s): string => $s->getName(),
            $registry->getMetricFamilySamples(),
        );
    }

    /**
     * @return array<array<int, string>>
     */
    private function labelValuesFor(CollectorRegistry $registry, string $name): array
    {
        foreach ($registry->getMetricFamilySamples() as $family) {
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
}
