<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration;

use ErrorException;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Monolog\Handler\HandlerDecorator;
use Msstc4Symfony\MetricsBundle\Infrastructure\Storage\Factory;
use Msstc4Symfony\MetricsBundle\Presentation\Controller\GetMetricsController;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\TestKernel;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use Prometheus\RegistryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\AttributeServicesLoader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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

    public function testMetricsEndpointServesPrometheusText(): void
    {
        $kernel = self::bootKernel();

        $response = $kernel->handle(Request::create('/_/metrics'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
    }

    public function testUnreachableRedisNeverTurnsRequestsInto500(): void
    {
        if (!\extension_loaded('redis')) {
            self::markTestSkipped('ext-redis required');
        }

        $_SERVER[self::DSN_ENV] = 'redis://metrics-unresolvable.invalid:6379?database=5';
        $kernel = self::bootKernel();

        // As Symfony's ErrorHandler with framework.php_errors.throw: PHP warnings become ErrorException.
        // The bundle's storage guard forwards other types (e.g. a compile-time deprecation of a lazily
        // loaded class) to this handler; like Symfony's, it must not throw for them.
        $thrown = \E_WARNING | \E_NOTICE | \E_USER_WARNING | \E_USER_NOTICE;
        set_error_handler(static function (int $type, string $message, string $file, int $line) use ($thrown): bool {
            if (($type & $thrown) === 0) {
                return false;
            }

            throw new ErrorException($message, 0, $type, $file, $line);
        }, $thrown);

        try {
            $page = $kernel->handle(Request::create('/no-such-page'));
            $metrics = $kernel->handle(Request::create('/_/metrics'));
        } finally {
            restore_error_handler();
        }

        self::assertSame(404, $page->getStatusCode());
        self::assertSame(503, $metrics->getStatusCode());
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function routingControllersProvider(): iterable
    {
        yield 'routing.controllers only' => [TestKernel::ENV_ROUTING_CONTROLLERS_ONLY];
        yield 'routing.controllers and the manual import' => [TestKernel::ENV_ROUTING_CONTROLLERS_AND_MANUAL_IMPORT];
    }

    #[DataProvider('routingControllersProvider')]
    public function testMetricsRouteLoadsOnceThroughRoutingControllers(string $environment): void
    {
        // symfony/routing may be newer than FrameworkBundle, which registers the loader only from 7.4.
        if (!class_exists(AttributeServicesLoader::class) || Kernel::VERSION_ID < 70400) {
            self::markTestSkipped('The "routing.controllers" resource needs Symfony 7.4+');
        }

        $kernel = self::bootKernel(['environment' => $environment]);

        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $paths = array_map(
            static fn (Route $route): string => $route->getPath(),
            $router->getRouteCollection()->all(),
        );

        self::assertSame([GetMetricsController::ROUTE_NAME => '/_/metrics'], $paths);
        self::assertSame(200, $kernel->handle(Request::create('/_/metrics'))->getStatusCode());
    }

    public function testApplicationLoggerIsDecoratedButStorageChannelIsNot(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertInstanceOf(HandlerDecorator::class, $container->get('logger'));
        self::assertNotInstanceOf(HandlerDecorator::class, $container->get('monolog.logger.' . Factory::LOG_CHANNEL));
    }

    public function testEachHttpRequestIsCountedOnceWithItsRealHost(): void
    {
        if (!class_exists(HttpClient::class)) {
            self::markTestSkipped('symfony/http-client not installed');
        }

        self::bootKernel();
        $container = self::getContainer();

        $default = $container->get(TestKernel::HTTP_CLIENT_ALIAS);
        $scoped = $container->get(TestKernel::SCOPED_CLIENT_ALIAS);
        self::assertInstanceOf(HttpClientInterface::class, $default);
        self::assertInstanceOf(HttpClientInterface::class, $scoped);

        $default->request('GET', 'https://example.com/a')->getContent();
        $scoped->request('GET', '/users/1')->getContent();

        $registry = $container->get(RegistryInterface::class);
        self::assertInstanceOf(RegistryInterface::class, $registry);

        $requests = [];
        foreach (RegistrySamples::samples($registry, MetricLabelEnum::HTTP_CONNECTION_REQUEST) as [[, , , $host, $path], $count]) {
            $requests[$host . $path] = $count;
        }

        ksort($requests);

        self::assertSame(['api.github.com/users/:id' => '1', 'example.com/a' => '1'], $requests);
    }
}
