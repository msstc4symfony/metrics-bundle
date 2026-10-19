<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Application\Port;

final readonly class MetricExport
{
    public function __construct(
        public string $body,
        public string $contentType,
    ) {
    }
}
