<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Infrastructure\Prometheus;

use InvalidArgumentException;
use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Application\Metric\Label;
use Msstc4Symfony\MetricsBundle\Application\Metric\Metric;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricRepository;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricType;
use Msstc4Symfony\MetricsBundle\Infrastructure\Prometheus\PrometheusMetricRecorder;
use Msstc4Symfony\MetricsBundle\Test\Support\CollectingLogger;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Exception\StorageException;
use Prometheus\RegistryInterface;
use Prometheus\Storage\InMemory;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;

#[CoversClass(PrometheusMetricRecorder::class)]
#[UsesClass(BuiltInMetric::class)]
#[UsesClass(Label::class)]
#[UsesClass(Metric::class)]
#[UsesClass(MetricRepository::class)]
final class PrometheusMetricRecorderTest extends TestCase
{
    private CollectorRegistry $registry;

    #[Override]
    protected function setUp(): void
    {
        $this->registry = new CollectorRegistry(new InMemory());
    }

    private function recorder(?RegistryInterface $registry = null, ?LoggerInterface $logger = null): PrometheusMetricRecorder
    {
        return new PrometheusMetricRecorder($registry ?? $this->registry, new MetricRepository(BuiltInMetric::cases()), 'app', 'cmp', $logger);
    }

    private function gaugeMetric(): BuiltInMetric
    {
        foreach (BuiltInMetric::cases() as $case) {
            if ($case->getType() === MetricType::GAUGE) {
                return $case;
            }
        }

        self::fail('BuiltInMetric declares no gauge');
    }

    public function testIncrementsACounterWithPrefixedLabels(): void
    {
        $this->recorder()->increment(BuiltInMetric::HTTP_REQUEST, ['GET', 'order_show']);

        self::assertSame([['app', 'cmp', 'GET', 'order_show']], RegistrySamples::labels($this->registry, BuiltInMetric::HTTP_REQUEST));
    }

    public function testCastsScalarLabelValuesToString(): void
    {
        $this->recorder()->increment(BuiltInMetric::HTTP_RESPONSE, ['GET', 'order_show', 404]);

        self::assertSame([['app', 'cmp', 'GET', 'order_show', '404']], RegistrySamples::labels($this->registry, BuiltInMetric::HTTP_RESPONSE));
    }

    public function testSetsAGauge(): void
    {
        $metric = $this->gaugeMetric();
        $this->recorder()->set($metric, 7);

        self::assertTrue(RegistrySamples::exists($this->registry, $metric));
    }

    public function testObservesAHistogramAndASummaryByType(): void
    {
        $this->recorder()->observe(BuiltInMetric::REQUEST_DURATION_HISTOGRAM_SECONDS, 0.1, ['GET', 'x']);
        $this->recorder()->observe(BuiltInMetric::REQUEST_DURATION_SUMMARY_SECONDS, 0.1, ['GET', 'x']);

        self::assertTrue(RegistrySamples::exists($this->registry, BuiltInMetric::REQUEST_DURATION_HISTOGRAM_SECONDS));
        self::assertTrue(RegistrySamples::exists($this->registry, BuiltInMetric::REQUEST_DURATION_SUMMARY_SECONDS));
    }

    public function testHistogramUsesTheDefinitionBuckets(): void
    {
        $metric = BuiltInMetric::REQUEST_DURATION_HISTOGRAM_SECONDS;
        $this->recorder()->observe($metric, 0.1, ['GET', 'x']);

        $bounds = array_map(static fn (array $labels): string => (string) end($labels), RegistrySamples::labels($this->registry, $metric, '_bucket'));

        self::assertSame([...array_map(strval(...), $metric->getBatches()), '+Inf'], $bounds);
    }

    public function testSummaryUsesTheStandardQuantiles(): void
    {
        $this->recorder()->observe(BuiltInMetric::REQUEST_DURATION_SUMMARY_SECONDS, 0.1, ['GET', 'x']);

        $quantiles = [];
        foreach ($this->registry->getMetricFamilySamples() as $family) {
            foreach ($family->getSamples() as $sample) {
                if ($sample->getName() === 'symfony_' . BuiltInMetric::REQUEST_DURATION_SUMMARY_SECONDS->value) {
                    $values = $sample->getLabelValues();
                    $quantiles[] = $values[array_key_last($values)];
                }
            }
        }

        self::assertSame([0.5, 0.9, 0.95, 0.99], $quantiles);
    }

    public function testSetRejectsACounter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('set() needs a gauge, "' . BuiltInMetric::HTTP_REQUEST->value . '" is a counter');

        $this->recorder()->set(BuiltInMetric::HTTP_REQUEST, 1, ['GET', 'x']);
    }

    public function testObserveRejectsAGauge(): void
    {
        $metric = $this->gaugeMetric();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('observe() needs a histogram or summary, "' . $metric->value . '" is a gauge');

        $this->recorder()->observe($metric, 1.0);
    }

    public function testRejectsAMetricOfAnotherType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('increment() needs a counter, "' . BuiltInMetric::REQUEST_DURATION_HISTOGRAM_SECONDS->value . '" is a histogram');

        $this->recorder()->increment(BuiltInMetric::REQUEST_DURATION_HISTOGRAM_SECONDS, ['GET', 'x']);
    }

    public function testLogsAStorageFailureInsteadOfThrowing(): void
    {
        $logger = new CollectingLogger();
        $registry = self::createStub(RegistryInterface::class);
        $registry->method('getOrRegisterCounter')->willThrowException(new StorageException('down'));

        $this->recorder($registry, $logger)->increment(BuiltInMetric::HTTP_REQUEST, ['GET', 'x']);

        self::assertSame(['error: Cannot save metric "' . BuiltInMetric::HTTP_REQUEST->value . '": down'], $logger->records);
    }

    public function testSwallowsAStorageFailureWithoutALogger(): void
    {
        $registry = self::createStub(RegistryInterface::class);
        $registry->method('getOrRegisterHistogram')->willThrowException(new StorageException('down'));

        $this->recorder($registry)->observe(BuiltInMetric::REQUEST_DURATION_HISTOGRAM_SECONDS, 0.1, ['GET', 'x']);

        $this->addToAssertionCount(1);
    }

    public function testSwallowsALoggerFailure(): void
    {
        $registry = self::createStub(RegistryInterface::class);
        $registry->method('getOrRegisterCounter')->willThrowException(new StorageException('down'));
        $logger = new class extends AbstractLogger {
            /**
             * @param array<array-key, mixed> $context
             */
            #[Override]
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException('logger down');
            }
        };

        $this->recorder($registry, $logger)->increment(BuiltInMetric::HTTP_REQUEST, ['GET', 'x']);

        $this->addToAssertionCount(1);
    }
}
