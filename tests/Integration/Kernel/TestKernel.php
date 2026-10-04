<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Kernel;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Msstc4Symfony\MetricsBundle\MetricsBundle;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\Messenger\TestMessage;
use Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\Messenger\TestMessageHandler;
use Override;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public const string HTTP_CLIENT_ALIAS = 'test.http_client';

    public const string SCOPED_CLIENT = 'github.client';

    public const string SCOPED_CLIENT_ALIAS = 'test.github.client';

    public const string MESSENGER_TRANSPORT = 'async';

    // Compiler passes of equal priority run in bundle registration order; this env flips it.
    public const string ENV_METRICS_BUNDLE_FIRST = 'metrics_first';

    // Symfony 7.4+ recipe: config/routes.yaml imports "routing.controllers" only.
    public const string ENV_ROUTING_CONTROLLERS_ONLY = 'routing_controllers';

    // An application that kept the manual import after switching to "routing.controllers".
    public const string ENV_ROUTING_CONTROLLERS_AND_MANUAL_IMPORT = 'routing_controllers_and_manual';

    // The test connection is sqlite in memory.
    public static function hasDoctrine(): bool
    {
        return class_exists(DoctrineBundle::class) && extension_loaded('pdo_sqlite');
    }

    public static function hasMessenger(): bool
    {
        return class_exists(WorkerMessageReceivedEvent::class);
    }

    // Per process: infection runs PHPUnit in parallel and setUp() wipes this directory.
    public static function cacheRoot(): string
    {
        return sys_get_temp_dir() . '/msstc4symfony-metrics-bundle-test-' . getmypid();
    }

    #[Override]
    public function registerBundles(): iterable
    {
        $bundles = [new FrameworkBundle(), new MonologBundle()];
        $doctrine = self::hasDoctrine() ? [new DoctrineBundle()] : [];

        return $this->environment === self::ENV_METRICS_BUNDLE_FIRST
            ? [...$bundles, new MetricsBundle(), ...$doctrine]
            : [...$bundles, ...$doctrine, new MetricsBundle()];
    }

    #[Override]
    public function getCacheDir(): string
    {
        return self::cacheRoot() . '/cache/' . $this->environment;
    }

    #[Override]
    public function getLogDir(): string
    {
        return self::cacheRoot() . '/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $framework = [
            'secret' => 'test',
            'http_method_override' => false,
            'test' => true,
            'router' => ['utf8' => true],
            // The php_errors logger installs a global handler that outlives the kernel and trips failOnRisky.
            'php_errors' => ['log' => false],
        ];

        if (class_exists(HttpClient::class)) {
            $framework['http_client'] = [
                'mock_response_factory' => 'test.mock_response',
                'scoped_clients' => [self::SCOPED_CLIENT => ['base_uri' => 'https://api.github.com']],
            ];
        }

        if (self::hasMessenger()) {
            $framework['messenger'] = [
                'transports' => [self::MESSENGER_TRANSPORT => 'in-memory://'],
                'routing' => [TestMessage::class => self::MESSENGER_TRANSPORT],
            ];
            $container->services()->set(TestMessageHandler::class)->autoconfigure();
        }

        $container->extension('framework', $framework);

        if (class_exists(HttpClient::class)) {
            $services = $container->services();
            $services->set('test.mock_response', MockResponseFactory::class);
            // Unused services are removed on compile; the test needs to fetch these.
            $services->alias(self::HTTP_CLIENT_ALIAS, 'http_client')->public();
            $services->alias(self::SCOPED_CLIENT_ALIAS, self::SCOPED_CLIENT)->public();
        }
        if (self::hasDoctrine()) {
            $container->extension('doctrine', [
                'dbal' => ['driver' => 'pdo_sqlite', 'memory' => true],
            ]);
        }

        $container->extension('monolog', [
            'handlers' => ['main' => ['type' => 'null']],
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        if (\in_array($this->environment, [self::ENV_ROUTING_CONTROLLERS_ONLY, self::ENV_ROUTING_CONTROLLERS_AND_MANUAL_IMPORT], true)) {
            $routes->import('routing.controllers');
        }

        if ($this->environment !== self::ENV_ROUTING_CONTROLLERS_ONLY) {
            // The manual import README gives applications without "routing.controllers".
            $routes->import('@MetricsBundle/Presentation/Controller/', 'attribute');
        }
    }
}
