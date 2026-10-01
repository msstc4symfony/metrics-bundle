<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Infrastructure\Doctrine\DBAL;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\DBAL\Connection;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\TestKernel;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use Prometheus\RegistryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Real DoctrineBundle wiring: the bare-ContainerBuilder pass tests cannot see whether
 * DoctrineBundle's MiddlewaresPass actually picks the middleware up.
 */
final class DoctrineDbalMetricsTest extends KernelTestCase
{
    private const string DSN_ENV = 'METRICS_STORAGE_DSN';

    #[Override]
    protected function setUp(): void
    {
        if (!class_exists(DoctrineBundle::class) || !class_exists(MonologBundle::class)) {
            self::markTestSkipped('doctrine/doctrine-bundle or symfony/monolog-bundle not installed');
        }

        new Filesystem()->remove(TestKernel::cacheRoot());
        $_SERVER[self::DSN_ENV] = 'inmemory://';
    }

    #[Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        unset($_SERVER[self::DSN_ENV]);
        new Filesystem()->remove(TestKernel::cacheRoot());
    }

    #[Override]
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function bundleOrderProvider(): iterable
    {
        yield 'DoctrineBundle registered first' => ['test'];
        yield 'MetricsBundle registered first' => [TestKernel::ENV_METRICS_BUNDLE_FIRST];
    }

    #[DataProvider('bundleOrderProvider')]
    public function testQueriesThroughTheDefaultConnectionAreCounted(string $environment): void
    {
        self::bootKernel(['environment' => $environment]);
        $container = self::getContainer();

        $connection = $container->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        $connection->executeStatement('CREATE TABLE users (id INTEGER)');
        $connection->executeStatement('INSERT INTO users (id) VALUES (?)', [1]);
        $connection->executeStatement('INSERT INTO users (id) VALUES (2)');
        $connection->executeQuery('SELECT id FROM users WHERE id = ?', [1])->fetchAllAssociative();
        $connection->executeQuery('SELECT id FROM users')->fetchAllAssociative();

        $registry = $container->get(RegistryInterface::class);
        self::assertInstanceOf(RegistryInterface::class, $registry);

        self::assertSame(
            [
                'localhost:db|insert|users' => '2',
                'localhost:db|other|unknown' => '1',
                'localhost:db|select|users' => '2',
            ],
            $this->samples($registry, MetricLabelEnum::DOCTRINE_QUERY_EXECUTE),
        );
        self::assertSame(
            [
                'localhost:db|insert|users' => '2',
                'localhost:db|other|unknown' => '1',
                'localhost:db|select|users' => '2',
            ],
            $this->samples($registry, MetricLabelEnum::DOCTRINE_QUERY_DURATION_HISTOGRAM_SECONDS, '_count'),
        );
    }

    /**
     * @return array<string, string>
     */
    private function samples(RegistryInterface $registry, MetricLabelEnum $metric, string $suffix = ''): array
    {
        $name = 'symfony_' . $metric->value;
        $values = [];
        foreach ($registry->getMetricFamilySamples() as $family) {
            if ($family->getName() !== $name) {
                continue;
            }

            foreach ($family->getSamples() as $sample) {
                if ($sample->getName() !== $name . $suffix) {
                    continue;
                }

                [, , $connection, $type, $table] = $sample->getLabelValues();
                self::assertIsString($connection);
                self::assertIsString($type);
                self::assertIsString($table);
                $values[$connection . '|' . $type . '|' . $table] = (string) $sample->getValue();
            }
        }

        ksort($values);

        return $values;
    }
}
