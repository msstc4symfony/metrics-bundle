<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\DependencyInjection\Compiler;

use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MessengerMetricLabelEnum;
use Override;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

/**
 * Appends the Messenger metrics to "metrics_bundle.metric_enums" after the application's
 * own value is merged: applications that list their enums replace the bundle's default list.
 */
final class RegisterMessengerMetricsPass implements CompilerPassInterface
{
    private const string METRIC_ENUMS_PARAMETER = 'metrics_bundle.metric_enums';

    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (!class_exists(WorkerMessageReceivedEvent::class) || !$container->hasParameter(self::METRIC_ENUMS_PARAMETER)) {
            return;
        }

        $enums = $container->getParameter(self::METRIC_ENUMS_PARAMETER);
        // An env/parameter placeholder resolves at runtime, as it always could; metrics:list then misses Messenger.
        if (!is_array($enums)) {
            return;
        }

        if (!in_array(MessengerMetricLabelEnum::class, $enums, true)) {
            $enums[] = MessengerMetricLabelEnum::class;
        }

        $container->setParameter(self::METRIC_ENUMS_PARAMETER, array_values($enums));
    }
}
