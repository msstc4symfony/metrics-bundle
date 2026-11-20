<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Application\Port;

use InvalidArgumentException;
use Msstc4Symfony\MetricsBundle\Application\Metric\MessengerMessageStatus;
use Msstc4Symfony\MetricsBundle\Application\Port\MessengerMeasurement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use stdClass;

final class MessengerMeasurementTest extends TestCase
{
    public function testExposesGivenValues(): void
    {
        $exception = new RuntimeException('boom');
        $measurement = new MessengerMeasurement('async', stdClass::class, MessengerMessageStatus::FAILED, 0.5, $exception);

        self::assertSame('async', $measurement->transport);
        self::assertSame(stdClass::class, $measurement->messageClass);
        self::assertSame(MessengerMessageStatus::FAILED, $measurement->status);
        self::assertSame(0.5, $measurement->durationSeconds);
        self::assertSame($exception, $measurement->exception);
    }

    public function testDurationAndExceptionAreOptional(): void
    {
        $measurement = new MessengerMeasurement('async', stdClass::class, MessengerMessageStatus::HANDLED, null, null);

        self::assertNull($measurement->durationSeconds);
        self::assertNull($measurement->exception);
    }

    public function testZeroDurationIsAccepted(): void
    {
        self::assertSame(0.0, (new MessengerMeasurement('async', stdClass::class, MessengerMessageStatus::HANDLED, 0.0, null))->durationSeconds);
    }

    public function testEmptyTransportIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // Through reflection: PHPStan rejects '' for the non-empty-string parameter.
        (new ReflectionClass(MessengerMeasurement::class))->newInstance('', stdClass::class, MessengerMessageStatus::HANDLED, 0.1, null);
    }

    public function testNegativeDurationIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MessengerMeasurement('async', stdClass::class, MessengerMessageStatus::HANDLED, -0.001, null);
    }
}
