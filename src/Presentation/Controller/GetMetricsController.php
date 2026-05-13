<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Presentation\Controller;

use Prometheus\RegistryInterface;
use Prometheus\RendererInterface;
use Prometheus\RenderTextFormat;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final class GetMetricsController extends AbstractController
{
    public const ROUTE_NAME = 'metrics-get';

    public function __construct(
        private readonly RendererInterface $renderer,
        private readonly RegistryInterface $registry,
    ) {
    }

    #[Route('/_/metrics', name: self::ROUTE_NAME, methods: 'GET')]
    public function get(): Response
    {
        return new Response(
            $this->renderer->render($this->registry->getMetricFamilySamples()),
            Response::HTTP_OK,
            [
                'Content-Type' => RenderTextFormat::MIME_TYPE,
                'Cache-Control' => 'private, max-age=5',
            ],
        );
    }
}
