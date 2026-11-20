<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Application\Port;

use InvalidArgumentException;
use Msstc4Symfony\MetricsBundle\Application\Port\HttpMeasurement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class HttpMeasurementTest extends TestCase
{
    public function testExposesGivenValues(): void
    {
        $exception = new RuntimeException('boom');
        $measurement = new HttpMeasurement('GET', 'order_show', 201, 0.25, $exception);

        self::assertSame('GET', $measurement->method);
        self::assertSame('order_show', $measurement->route);
        self::assertSame(201, $measurement->status);
        self::assertSame(0.25, $measurement->durationSeconds);
        self::assertSame($exception, $measurement->exception);
    }

    public function testRouteAndExceptionAreOptional(): void
    {
        $measurement = new HttpMeasurement('POST', null, 100, 0.0, null);

        self::assertNull($measurement->route);
        self::assertNull($measurement->exception);
    }

    public function testHttpRangeBoundsAreAccepted(): void
    {
        self::assertSame(599, (new HttpMeasurement('GET', null, 599, 0.0, null))->status);
    }

    #[DataProvider('invalidStatuses')]
    public function testStatusOutsideHttpRangeIsRejected(int $status): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->construct('GET', null, $status);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidStatuses(): iterable
    {
        yield 'below 100' => [99];
        yield 'above 599' => [600];
    }

    public function testNegativeDurationIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HttpMeasurement('GET', null, 200, -0.001, null);
    }

    public function testEmptyRouteIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->construct('GET', '', 200);
    }

    #[DataProvider('recordedMethods')]
    public function testRecordedMethodIsAccepted(string $method): void
    {
        self::assertSame($method, $this->construct($method, null, 200)->method);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function recordedMethods(): iterable
    {
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OTHER'] as $method) {
            yield $method => [$method];
        }
    }

    #[DataProvider('unrecordedMethods')]
    public function testMethodOutsideTheLabelValuesIsRejected(string $method): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf('HTTP method must be one of GET, POST, DELETE, PUT, PATCH, HEAD, OTHER, "%s" given.', $method));

        $this->construct($method, null, 200);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unrecordedMethods(): iterable
    {
        yield 'empty' => [''];
        yield 'lower case' => ['get'];
        yield 'non-standard' => ['PROPFIND'];
        yield 'not recorded' => ['OPTIONS'];
    }

    // Through reflection: PHPStan rejects values outside the narrowed parameter types.
    private function construct(string $method, ?string $route, int $status): HttpMeasurement
    {
        return (new ReflectionClass(HttpMeasurement::class))->newInstance($method, $route, $status, 0.1, null);
    }
}
