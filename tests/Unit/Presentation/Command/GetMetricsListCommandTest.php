<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Presentation\Command;

use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Application\Metric\Metric;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricRepositoryInterface;
use Msstc4Symfony\MetricsBundle\Presentation\Command\GetMetricsListCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class GetMetricsListCommandTest extends TestCase
{
    public function testInvoke(): void
    {
        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findAll')
            ->willReturn(
                [
                    new Metric(
                        BuiltInMetric::HTTP_REQUEST,
                        BuiltInMetric::HTTP_REQUEST->getType(),
                        BuiltInMetric::HTTP_REQUEST->getDescription(),
                        BuiltInMetric::HTTP_REQUEST->getLabels(),
                        BuiltInMetric::HTTP_REQUEST->getBatches(),
                    ),
                ],
            )
        ;

        $command = new GetMetricsListCommand();
        $command->setRepository($repository);

        $commandTester = new CommandTester($command);

        $exitCode = $commandTester->execute([]);

        self::assertSame(0, $exitCode);

        $display = $commandTester->getDisplay();

        self::assertStringContainsString('Name', $display);
        self::assertStringContainsString('Type', $display);
        self::assertStringContainsString('Description', $display);
        self::assertStringContainsString('Labels', $display);
        self::assertStringContainsString('Histogram batches', $display);
        self::assertStringContainsString('http_request', $display);
        self::assertStringContainsString('counter', $display);
        self::assertStringContainsString('Incoming HTTP request count', $display);
        self::assertStringContainsString('method', $display);
        self::assertStringContainsString('GET, POST, DELETE, PUT, PATCH, HEAD', $display);
        self::assertStringContainsString('route', $display);
        self::assertStringContainsString('Route name or URL path', $display);
    }
}
