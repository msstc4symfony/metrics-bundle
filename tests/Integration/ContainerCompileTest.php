<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration;

use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Monolog\Handler\HandlerDecorator;
use Msstc4Symfony\MetricsBundle\Infrastructure\Storage\Factory;
use Msstc4Symfony\MetricsBundle\Presentation\Controller\GetMetricsController;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\TestKernel;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use Prometheus\RegistryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Request;
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
        if (!class_exists(AttributeServicesLoader::class)) {
            self::markTestSkipped('The "routing.controllers" resource needs symfony/routing 7.4+');
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
        foreach ($registry->getMetricFamilySamples() as $family) {
            if ($family->getName() !== 'symfony_' . MetricLabelEnum::HTTP_CONNECTION_REQUEST->value) {
                continue;
            }

            foreach ($family->getSamples() as $sample) {
                [, , , $host, $path] = $sample->getLabelValues();
                self::assertIsString($host);
                self::assertIsString($path);
                $requests[$host . $path] = (string) $sample->getValue();
            }
        }

        ksort($requests);

        self::assertSame(['api.github.com/users/:id' => '1', 'example.com/a' => '1'], $requests);
    }
}
