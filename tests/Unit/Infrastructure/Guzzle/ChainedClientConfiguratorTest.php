<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Infrastructure\Guzzle;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Msstc4Symfony\MetricsBundle\Application\Collector\ExternalConnectionCollector;
use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Infrastructure\Guzzle\ChainedClientConfigurator;
use Msstc4Symfony\MetricsBundle\Infrastructure\Guzzle\MetricsGuzzleMiddleware;
use Msstc4Symfony\MetricsBundle\Infrastructure\OutgoingHttp\OutgoingRequestLabels;
use Msstc4Symfony\MetricsBundle\Test\Support\RecordingMetricRecorder;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;

#[RequiresMethod(ClientInterface::class, 'send')]
final class ChainedClientConfiguratorTest extends TestCase
{
    public function testRunsTheOwnConfiguratorFirstAndThenAddsTheMiddleware(): void
    {
        $recorder = new RecordingMetricRecorder();
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200)]))]);
        $configured = [];

        $configurator = new ChainedClientConfigurator(
            static function (ClientInterface $client) use (&$configured, $recorder): void {
                $configured[] = $client;
                self::assertSame([], $recorder->calls, 'the own configurator runs first');
            },
            new MetricsGuzzleMiddleware(new ExternalConnectionCollector($recorder), new OutgoingRequestLabels([], true)),
        );
        $configurator($client);
        $client->request('GET', 'https://example.com');

        self::assertSame([$client], $configured);
        self::assertSame(BuiltInMetric::HTTP_CONNECTION_REQUEST, $recorder->calls[0][1] ?? null);
    }
}
