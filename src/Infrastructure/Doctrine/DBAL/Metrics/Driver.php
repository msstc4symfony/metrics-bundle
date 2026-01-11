<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use MaxShamaev\MetricsBundle\Infrastructure\Collector\DoctrineConnectionCollector;
use SensitiveParameter;

final class Driver extends AbstractDriverMiddleware
{
    /** @internal This driver can be only instantiated by its middleware. */
    public function __construct(
        DriverInterface $driver,
        private readonly DoctrineConnectionCollector $collector,
    ) {
        parent::__construct($driver);
    }

    public function connect(
        #[SensitiveParameter]
        array $params,
    ): DriverInterface\Connection {
        return new Connection(
            parent::connect($params),
            $this->collector,
            $this->assembleConnectionName($params),
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
