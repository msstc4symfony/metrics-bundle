<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Framework\Monolog;

use Monolog\Handler\TestHandler;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\TestKernel;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\Filesystem\Filesystem;

#[CoversNothing]
final class NoRecursionTest extends KernelTestCase
{
    #[Override]
    protected function setUp(): void
    {
        if (!class_exists(MonologBundle::class)) {
            self::markTestSkipped('symfony/monolog-bundle not installed');
        }

        (new Filesystem())->remove(TestKernel::cacheRoot());
    }

    #[Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        (new Filesystem())->remove(TestKernel::cacheRoot());
    }

    public function testStorageFailureWhileCountingAnErrorLogsOnceWithoutRecursion(): void
    {
        self::bootKernel(['environment' => TestKernel::ENV_FAILING_STORAGE]);
        $container = self::getContainer();

        $logger = $container->get('monolog.logger');
        self::assertInstanceOf(LoggerInterface::class, $logger);
        $logger->error('boom');

        $metrics = $container->get('monolog.handler.metrics');
        self::assertInstanceOf(TestHandler::class, $metrics);
        self::assertCount(1, $metrics->getRecords());
        self::assertStringStartsWith('Cannot save metric "error"', $metrics->getRecords()[0]->message);
    }

    #[Override]
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }
}
