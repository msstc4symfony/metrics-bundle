<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Presentation\Controller;

use Monolog\Attribute\WithMonologChannel;
use Msstc4Symfony\MetricsBundle\Application\LogChannel;
use Msstc4Symfony\MetricsBundle\Application\Port\MetricStorageException;
use Msstc4Symfony\MetricsBundle\Application\Port\MetricStorageInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * @internal
 */
#[AsController]
#[WithMonologChannel(LogChannel::NAME)]
final class GetMetricsController extends AbstractController
{
    public const ROUTE_NAME = 'metrics-get';

    // Prometheus text exposition format, also for the 503 body.
    private const UNAVAILABLE_CONTENT_TYPE = 'text/plain; version=0.0.4';

    private const CACHE_CONTROL = 'no-store, max-age=0';

    public function __construct(
        private readonly MetricStorageInterface $storage,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/_/metrics', name: self::ROUTE_NAME, methods: 'GET')]
    public function get(): Response
    {
        try {
            $export = $this->storage->export();
        } catch (MetricStorageException $exception) {
            try {
                $this->logger->error('metrics-bundle: metrics cannot be collected', ['exception' => $exception]);
            } catch (Throwable) {
                // Logger failures must not turn the 503 into a 500.
            }

            // Prometheus records a non-200 scrape as up == 0 instead of an empty, healthy-looking target.
            return new Response("# metrics unavailable\n", Response::HTTP_SERVICE_UNAVAILABLE, [
                'Content-Type' => self::UNAVAILABLE_CONTENT_TYPE,
                'Cache-Control' => self::CACHE_CONTROL,
            ]);
        }

        return new Response($export->body, Response::HTTP_OK, [
            'Content-Type' => $export->contentType,
            'Cache-Control' => self::CACHE_CONTROL,
        ]);
    }
}
