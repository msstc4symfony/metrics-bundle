<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Application\Port;

use InvalidArgumentException;
use Msstc4Symfony\MetricsBundle\Application\Port\HttpMeasurement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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

    #[DataProvider('invalidStatuses')]
    public function testStatusOutsideHttpRangeIsRejected(int $status): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HttpMeasurement('GET', null, $status, 0.1, null);
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

    public function testEmptyMethodIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HttpMeasurement('', null, 200, 0.1, null);
    }

    public function testLowerCaseMethodIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HttpMeasurement('get', null, 200, 0.1, null);
    }
}
