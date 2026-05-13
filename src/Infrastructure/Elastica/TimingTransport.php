<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Elastica;

use Elastica\Request;
use Elastica\Response;
use Elastica\Transport\AbstractTransport;
use LogicException;
use MaxShamaev\MetricsBundle\Infrastructure\Collector\ElasticaCollector;
use Throwable;

class TimingTransport extends AbstractTransport
{
    private ?AbstractTransport $inner = null;

    private ?ElasticaCollector $collector = null;

    public function init(
        AbstractTransport $inner,
        ElasticaCollector $collector,
    ): static {
        $this->inner = $inner;
        $this->collector = $collector;

        return $this;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function exec(Request $request, array $params): Response
    {
        if (!$this->inner instanceof AbstractTransport || !$this->collector instanceof ElasticaCollector) {
            throw new LogicException(self::class . '::init() must be called before exec().');
        }

        try {
            $response = $this->inner->exec($request, $params);
            $this->collector->incRequestSuccess($request->getMethod(), $request->getPath());
            $this->collector->setRequestDuration($request->getMethod(), $request->getPath(), $response->getQueryTime());
        } catch (Throwable $exception) {
            $this->collector->incRequestFailed($request->getMethod(), $request->getPath());
            throw $exception;
        }

        return $response;
    }
}
