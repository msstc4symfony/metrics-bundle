<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Application\Collector;

use Msstc4Symfony\MetricsBundle\Application\Collector\ErrorCollector;
use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(ErrorCollector::class)]
final class ErrorCollectorTest extends RecorderTestCase
{
    public function testIncExceptionRegistersFqcnLabel(): void
    {
        $this->build(useShort: false)->incException(new RuntimeException('boom'));

        self::assertSame([['increment', BuiltInMetric::EXCEPTION, null, [RuntimeException::class]]], $this->recorder->calls);
    }

    public function testIncExceptionWithShortClassNameStripsNamespace(): void
    {
        $this->build(useShort: true)->incException(new RuntimeException('boom'));

        self::assertSame([['increment', BuiltInMetric::EXCEPTION, null, ['RuntimeException']]], $this->recorder->calls);
    }

    public function testIgnoredExceptionsAreSkipped(): void
    {
        $this->build()->incException(new NotFoundHttpException());

        self::assertSame([], $this->recorder->calls);
    }

    public function testIncErrorLabelsByLevelName(): void
    {
        $this->build()->incError('ERROR');

        self::assertSame([['increment', BuiltInMetric::ERROR, null, ['ERROR']]], $this->recorder->calls);
    }

    private function build(bool $useShort = false): ErrorCollector
    {
        return new ErrorCollector($this->recorder, $useShort);
    }
}
