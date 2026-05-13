<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Test\Unit\Presentation\Command;

use MaxShamaev\MetricsBundle\Infrastructure\Entity\Metric;
use MaxShamaev\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use MaxShamaev\MetricsBundle\Infrastructure\Repository\MetricRepositoryInterface;
use MaxShamaev\MetricsBundle\Presentation\Command\GetMetricsListCommand;
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
                        MetricLabelEnum::HTTP_REQUEST,
                        MetricLabelEnum::HTTP_REQUEST->getType(),
                        MetricLabelEnum::HTTP_REQUEST->getDescription(),
                        MetricLabelEnum::HTTP_REQUEST->getLabels(),
                        MetricLabelEnum::HTTP_REQUEST->getBatches(),
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
