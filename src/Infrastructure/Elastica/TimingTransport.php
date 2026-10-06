<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Elastica;

use Elastica\Request;
use Elastica\Response;
use Elastica\Transport\AbstractTransport;
use LogicException;
use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\ElasticaCollector;
use Override;
use Throwable;

final class TimingTransport extends AbstractTransport
{
    private ?AbstractTransport $inner = null;

    private ?ElasticaCollector $collector = null;

    private bool $sanitizePath = true;

    public function init(
        AbstractTransport $inner,
        ElasticaCollector $collector,
        bool $sanitizePath = true,
    ): static {
        $this->inner = $inner;
        $this->collector = $collector;
        $this->sanitizePath = $sanitizePath;

        return $this;
    }

    /**
     * @param array<string, mixed> $params
     */
    #[Override]
    public function exec(Request $request, array $params): Response
    {
        if (!$this->inner instanceof AbstractTransport || !$this->collector instanceof ElasticaCollector) {
            throw new LogicException(self::class . '::init() must be called before exec().');
        }

        $method = $request->getMethod();
        $path = $this->sanitizePath ? ElasticaPathSanitizer::sanitize($request->getPath()) : $request->getPath();
        $start = hrtime(true);

        try {
            $response = $this->inner->exec($request, $params);
            // Only the HTTP transports set the query time; NullTransport and custom ones leave it null.
            $queryTime = $response->getQueryTime();
            $this->collector->incRequestSuccess($method, $path);
            $this->collector->setRequestDuration(
                $method,
                $path,
                \is_float($queryTime) || \is_int($queryTime) ? $queryTime : (hrtime(true) - $start) / 1e9,
            );
        } catch (Throwable $exception) {
            $this->collector->incRequestFailed($method, $path);
            throw $exception;
        }

        return $response;
    }
}
