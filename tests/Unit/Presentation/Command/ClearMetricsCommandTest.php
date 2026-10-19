<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Presentation\Command;

use Msstc4Symfony\MetricsBundle\Application\Port\MetricStorageException;
use Msstc4Symfony\MetricsBundle\Application\Port\MetricStorageInterface;
use Msstc4Symfony\MetricsBundle\Presentation\Command\ClearMetricsCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ClearMetricsCommand::class)]
final class ClearMetricsCommandTest extends TestCase
{
    public function testInvoke(): void
    {
        $storage = $this->createMock(MetricStorageInterface::class);
        $storage->expects(self::once())->method('clear');
        $command = new ClearMetricsCommand();
        $command->setStorage($storage);

        $commandTester = new CommandTester($command);

        self::assertSame(0, $commandTester->execute([]));
        self::assertStringContainsString('Clearing storage', $commandTester->getDisplay());
        self::assertStringContainsString('[OK] The storage was successfully cleared.', $commandTester->getDisplay());
    }

    public function testUnavailableStorageFailsWithAMessage(): void
    {
        $storage = self::createStub(MetricStorageInterface::class);
        $storage->method('clear')->willThrowException(new MetricStorageException('Metric storage wipeStorage failed: Connection refused'));
        $command = new ClearMetricsCommand();
        $command->setStorage($storage);

        $commandTester = new CommandTester($command);

        self::assertSame(1, $commandTester->execute([]));
        self::assertStringContainsString('Connection refused', $commandTester->getDisplay());
    }
}
