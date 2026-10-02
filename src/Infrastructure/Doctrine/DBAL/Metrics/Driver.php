<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Msstc4Symfony\MetricsBundle\Infrastructure\Collector\DoctrineConnectionCollector;
use Override;
use SensitiveParameter;

final class Driver extends AbstractDriverMiddleware
{
    /** @internal This driver can be only instantiated by its middleware. */
    public function __construct(
        DriverInterface $driver,
        private readonly DoctrineConnectionCollector $collector,
        private readonly ?string $connectionName = null,
    ) {
        parent::__construct($driver);
    }

    #[Override]
    public function connect(
        #[SensitiveParameter]
        array $params,
    ): DriverInterface\Connection {
        return new Connection(
            parent::connect($params),
            $this->collector,
            $this->connectionName ?? $this->assembleConnectionName($params),
        );
    }

    /**
     * @param array{host?: ?string, dbname?: ?string} $params
     */
    private function assembleConnectionName(array $params): string
    {
        return ($params['host'] ?? 'localhost') . ':' . ($params['dbname'] ?? 'db');
    }
}
