<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Elastica;

use Http\Discovery\Psr18ClientDiscovery;
use LogicException;
use Psr\Http\Client\ClientInterface;
use ReflectionClass;
use Throwable;

/**
 * Elastica 8: builds the PSR-18 client the way Elastica\Client::setTransportClientOptions() would, so that
 * TimingHttpClient can wrap it — Elastica looks the options adapter up by the concrete client class and
 * cannot apply "http_client_config"/"http_client_options" to the wrapper.
 *
 * The elasticsearch-php 8 classes are named by string: the CI lock carries Elastica 7, where they do not
 * exist, and PHPStan analyses this file there as well.
 *
 * @internal
 */
final class ConfiguredHttpClientFactory
{
    private const string ADAPTERS = 'Elastic\Elasticsearch\Transport\Adapter\AdapterOptions::HTTP_ADAPTERS';

    private const string ADAPTER_INTERFACE = 'Elastic\Elasticsearch\Transport\Adapter\AdapterInterface';

    /**
     * A property, not a constant: PHPStan resolves a constant to the literal class and rejects it on Elastica 7.
     */
    private static string $exceptionClass = 'Elastic\Elasticsearch\Exception\HttpClientException';

    /**
     * @param array<array-key, mixed>|null $config transport_config.http_client_config
     * @param array<array-key, mixed>|null $options transport_config.http_client_options
     */
    public static function create(?ClientInterface $client, ?array $config, ?array $options): ClientInterface
    {
        $client ??= Psr18ClientDiscovery::find();
        $config ??= [];
        $options ??= [];

        if ($config === [] && $options === []) {
            return $client;
        }

        $adapters = \defined(self::ADAPTERS) ? \constant(self::ADAPTERS) : [];
        $adapterClass = \is_array($adapters) ? $adapters[$client::class] ?? null : null;
        if (!\is_string($adapterClass)) {
            throw self::exception(\sprintf('The HTTP client %s is not supported for custom options', $client::class));
        }

        if (!class_exists($adapterClass) || !\in_array(self::ADAPTER_INTERFACE, class_implements($adapterClass), true)) {
            throw self::exception(\sprintf('The class %s does not exists or does not implement %s', $adapterClass, self::ADAPTER_INTERFACE));
        }

        $setConfig = [new $adapterClass(), 'setConfig'];
        $configured = \is_callable($setConfig) ? $setConfig($client, $config, $options) : null;
        if (!$configured instanceof ClientInterface) {
            throw new LogicException(\sprintf('%s::setConfig() did not return a PSR-18 client.', $adapterClass));
        }

        return $configured;
    }

    private static function exception(string $message): Throwable
    {
        $exception = class_exists(self::$exceptionClass) ? new ReflectionClass(self::$exceptionClass)->newInstance($message) : null;

        return $exception instanceof Throwable ? $exception : new LogicException($message);
    }
}
