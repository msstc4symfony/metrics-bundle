<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration;

use Msstc4Symfony\MetricsBundle\Infrastructure\HttpClient\HttpClientDecorator;
use Msstc4Symfony\MetricsBundle\Infrastructure\Monolog\Handler\HandlerDecorator;
use Msstc4Symfony\MetricsBundle\Infrastructure\Storage\Factory;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\TestKernel;
use Override;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Request;

/**
 * Boots FrameworkBundle + MonologBundle + MetricsBundle. The compiler-pass tests use a
 * bare ContainerBuilder and cannot see cycles or wiring that only exist in a real app.
 */
final class ContainerCompileTest extends KernelTestCase
{
    private const string DSN_ENV = 'METRICS_STORAGE_DSN';

    #[Override]
    protected function setUp(): void
    {
        if (!class_exists(MonologBundle::class)) {
            self::markTestSkipped('symfony/monolog-bundle not installed');
        }

        new Filesystem()->remove(sys_get_temp_dir() . TestKernel::CACHE_ROOT);
        $_SERVER[self::DSN_ENV] = 'inmemory://';
    }

    #[Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        unset($_SERVER[self::DSN_ENV]);
    }

    #[Override]
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testMetricsEndpointServesPrometheusText(): void
    {
        $kernel = self::bootKernel();

        $response = $kernel->handle(Request::create('/_/metrics'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
    }

    public function testApplicationLoggerIsDecoratedButStorageChannelIsNot(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertInstanceOf(HandlerDecorator::class, $container->get('logger'));
        self::assertNotInstanceOf(HandlerDecorator::class, $container->get('monolog.logger.' . Factory::LOG_CHANNEL));
    }

    public function testHttpClientIsDecorated(): void
    {
        if (!class_exists(HttpClient::class)) {
            self::markTestSkipped('symfony/http-client not installed');
        }

        self::bootKernel();

        self::assertInstanceOf(HttpClientDecorator::class, self::getContainer()->get(TestKernel::HTTP_CLIENT_ALIAS));
    }
}
