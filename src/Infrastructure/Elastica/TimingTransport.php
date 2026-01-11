<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Elastica;

use Elastica\Request;
use Elastica\Response;
use Elastica\Transport\AbstractTransport;
use MaxShamaev\MetricsBundle\Infrastructure\Collector\ElasticaCollector;
use Throwable;

class TimingTransport extends AbstractTransport
{
    private AbstractTransport $inner;

    private ElasticaCollector $collector;

    public function init(
        AbstractTransport $inner,
        ElasticaCollector $collector,
    ): static {
        $this->inner = $inner;
        $this->collector = $collector;

        return $this;
    }

    public function exec(Request $request, array $params): Response
    {
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
