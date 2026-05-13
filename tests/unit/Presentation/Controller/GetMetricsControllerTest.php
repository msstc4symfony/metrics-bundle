<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Test\Unit\Presentation\Controller;

use MaxShamaev\MetricsBundle\Presentation\Controller\GetMetricsController;
use PHPUnit\Framework\TestCase;
use Prometheus\MetricFamilySamples;
use Prometheus\RegistryInterface;
use Prometheus\RenderTextFormat;

final class GetMetricsControllerTest extends TestCase
{
    public function testGet(): void
    {
        $render = new RenderTextFormat();
        $registry = $this->createMock(RegistryInterface::class);
        $registry->expects(self::once())
            ->method('getMetricFamilySamples')
            ->willReturn(
                [
                    new MetricFamilySamples(
                        [
                            'name' => 'test_counter',
                            'type' => 'counter',
                            'help' => 'Test counter',
                            'labelNames' => ['app'],
                            'samples' => [
                                ['name' => 'test_counter', 'labelNames' => [], 'labelValues' => ['test'], 'value' => 10],
                            ],
                        ],
                    ),
                ],
            )
        ;

        $controller = new GetMetricsController($render, $registry);
        $response = $controller->get();

        self::assertSame(
            <<<TXT
# HELP test_counter Test counter
# TYPE test_counter counter
test_counter{app="test"} 10

TXT,
            $response->getContent(),
        );
        self::assertSame(RenderTextFormat::MIME_TYPE, $response->headers->get('Content-Type'));
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('max-age=5', (string) $response->headers->get('Cache-Control'));
    }
}
