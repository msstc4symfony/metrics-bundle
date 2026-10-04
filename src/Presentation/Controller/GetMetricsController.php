<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Presentation\Controller;

use Monolog\Attribute\WithMonologChannel;
use Msstc4Symfony\MetricsBundle\Infrastructure\Storage\Factory;
use Prometheus\RegistryInterface;
use Prometheus\RendererInterface;
use Prometheus\RenderTextFormat;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

#[AsController]
#[WithMonologChannel(Factory::LOG_CHANNEL)]
final class GetMetricsController extends AbstractController
{
    public const string ROUTE_NAME = 'metrics-get';

    private const array HEADERS = [
        'Content-Type' => RenderTextFormat::MIME_TYPE,
        'Cache-Control' => 'no-store, max-age=0',
    ];

    public function __construct(
        private readonly RendererInterface $renderer,
        private readonly RegistryInterface $registry,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/_/metrics', name: self::ROUTE_NAME, methods: 'GET')]
    public function get(): Response
    {
        try {
            $body = $this->renderer->render($this->registry->getMetricFamilySamples());
        } catch (Throwable $exception) {
            try {
                $this->logger->error('metrics-bundle: metrics cannot be collected', ['exception' => $exception]);
            } catch (Throwable) {
                // Logger failures must not turn the 503 into a 500.
            }

            // Prometheus records a non-200 scrape as up == 0 instead of an empty, healthy-looking target.
            return new Response("# metrics unavailable\n", Response::HTTP_SERVICE_UNAVAILABLE, self::HEADERS);
        }

        return new Response($body, Response::HTTP_OK, self::HEADERS);
    }
}
