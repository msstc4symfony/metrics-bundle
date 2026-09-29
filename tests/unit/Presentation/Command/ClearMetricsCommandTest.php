<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Presentation\Command;

use Msstc4Symfony\MetricsBundle\Presentation\Command\ClearMetricsCommand;
use PHPUnit\Framework\TestCase;
use Prometheus\Storage\Adapter;
use Symfony\Component\Console\Tester\CommandTester;

final class ClearMetricsCommandTest extends TestCase
{
    public function testInvoke(): void
    {
        $storage = $this->createMock(Adapter::class);
        $storage->expects(self::once())
            ->method('wipeStorage')
        ;

        $command = new ClearMetricsCommand();
        $command->setStorage($storage);

        $commandTester = new CommandTester($command);

        $exitCode = $commandTester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Clearing storage', $commandTester->getDisplay());
        self::assertStringContainsString('[OK] The storage was successfully cleared.', $commandTester->getDisplay());
    }
}
