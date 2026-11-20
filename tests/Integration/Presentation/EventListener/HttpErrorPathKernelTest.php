<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Presentation\EventListener;

use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\TestKernel;
use Msstc4Symfony\MetricsBundle\Test\Support\RegistrySamples;
use Override;
use Prometheus\RegistryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

final class HttpErrorPathKernelTest extends KernelTestCase
{
    private const DSN_ENV = 'METRICS_STORAGE_DSN';

    #[Override]
    protected function setUp(): void
    {
        if (!class_exists(MonologBundle::class)) {
            self::markTestSkipped('symfony/monolog-bundle not installed');
        }

        (new Filesystem())->remove(TestKernel::cacheRoot());
        $_SERVER[self::DSN_ENV] = 'inmemory://';
    }

    #[Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        unset($_SERVER[self::DSN_ENV]);
        (new Filesystem())->remove(TestKernel::cacheRoot());
    }

    public function testUncaughtExceptionIsRecordedAs500(): void
    {
        $kernel = self::bootKernel();

        $this->serve($kernel, '/_test/throw');

        self::assertSame(
            [[['unknown', 'unknown', 'GET', 'test_throw'], '1']],
            RegistrySamples::samples($this->registry(), BuiltInMetric::HTTP_REQUEST),
        );
        self::assertSame(
            [['unknown', 'unknown', 'GET', 'test_throw', '500']],
            RegistrySamples::labels($this->registry(), BuiltInMetric::HTTP_RESPONSE),
        );
    }

    public function testHttpCacheMissIsRecordedOnce(): void
    {
        $kernel = self::bootKernel(['environment' => TestKernel::ENV_HTTP_CACHE]);

        $this->serve($kernel, '/_test/ok');

        self::assertSame(
            [[['unknown', 'unknown', 'GET', 'test_ok'], '1']],
            RegistrySamples::samples($this->registry(), BuiltInMetric::HTTP_REQUEST),
        );
        self::assertSame(
            [[['unknown', 'unknown', 'GET', 'test_ok', '200'], '1']],
            RegistrySamples::samples($this->registry(), BuiltInMetric::HTTP_RESPONSE),
        );
        self::assertSame(
            [[['unknown', 'unknown', 'GET', 'test_ok'], '1']],
            RegistrySamples::samples($this->registry(), BuiltInMetric::REQUEST_DURATION_HISTOGRAM_SECONDS, '_count'),
        );
    }

    public function testRouterNotFoundIsRecordedAsUnknown404(): void
    {
        $kernel = self::bootKernel();

        $this->serve($kernel, '/_test/missing');

        self::assertSame(
            [['unknown', 'unknown', 'GET', 'unknown']],
            RegistrySamples::labels($this->registry(), BuiltInMetric::HTTP_REQUEST),
        );
        self::assertSame(
            [['unknown', 'unknown', 'GET', 'unknown', '404']],
            RegistrySamples::labels($this->registry(), BuiltInMetric::HTTP_RESPONSE),
        );
    }

    public function testFragmentSubRequestKeepsMainMeasurement(): void
    {
        $kernel = self::bootKernel();

        $this->serve($kernel, '/_test/fragment');

        self::assertSame(
            [['unknown', 'unknown', 'GET', 'test_fragment', '200']],
            RegistrySamples::labels($this->registry(), BuiltInMetric::HTTP_RESPONSE),
        );
    }

    public function testIgnoredRoutesConfigIsApplied(): void
    {
        $kernel = self::bootKernel(['environment' => TestKernel::ENV_HTTP_IGNORED_ROUTES]);

        $this->serve($kernel, '/_test/throw');

        self::assertFalse(RegistrySamples::exists($this->registry(), BuiltInMetric::HTTP_REQUEST));
        self::assertFalse(RegistrySamples::exists($this->registry(), BuiltInMetric::HTTP_RESPONSE));
    }

    #[Override]
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    private function serve(KernelInterface $kernel, string $uri): void
    {
        $request = Request::create($uri);
        self::assertInstanceOf(TerminableInterface::class, $kernel);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
    }

    private function registry(): RegistryInterface
    {
        $registry = self::getContainer()->get(RegistryInterface::class);
        self::assertInstanceOf(RegistryInterface::class, $registry);

        return $registry;
    }
}
