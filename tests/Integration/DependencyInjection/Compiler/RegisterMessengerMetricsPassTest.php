<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\DependencyInjection\Compiler;

use Msstc4Symfony\MetricsBundle\DependencyInjection\Compiler\RegisterMessengerMetricsPass;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MessengerMetricLabelEnum;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum;
use Override;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

final class RegisterMessengerMetricsPassTest extends TestCase
{
    private const string PARAMETER = 'metrics_bundle.metric_enums';

    #[Override]
    protected function setUp(): void
    {
        if (!class_exists(WorkerMessageReceivedEvent::class)) {
            self::markTestSkipped('symfony/messenger not installed');
        }
    }

    public function testAppendsTheMessengerEnumToAnApplicationDefinedList(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(self::PARAMETER, [MetricLabelEnum::class, 'App\Metrics\AppMetrics']);

        new RegisterMessengerMetricsPass()->process($container);

        self::assertSame(
            [MetricLabelEnum::class, 'App\Metrics\AppMetrics', MessengerMetricLabelEnum::class],
            $container->getParameter(self::PARAMETER),
        );
    }

    public function testDoesNotDuplicateAnAlreadyListedEnum(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(self::PARAMETER, [MessengerMetricLabelEnum::class, MetricLabelEnum::class]);

        new RegisterMessengerMetricsPass()->process($container);

        self::assertSame([MessengerMetricLabelEnum::class, MetricLabelEnum::class], $container->getParameter(self::PARAMETER));
    }

    public function testLeavesARuntimeResolvedListAlone(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(self::PARAMETER, '%env(json:METRIC_ENUMS)%');

        new RegisterMessengerMetricsPass()->process($container);

        self::assertSame('%env(json:METRIC_ENUMS)%', $container->getParameter(self::PARAMETER));
    }

    public function testLeavesAContainerWithoutTheBundleParameterAlone(): void
    {
        $container = new ContainerBuilder();

        new RegisterMessengerMetricsPass()->process($container);

        self::assertFalse($container->hasParameter(self::PARAMETER));
    }
}
