<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Infrastructure\Collector;

use MaxShamaev\MetricsBundle\Infrastructure\Entity\Metric;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\Attribute\Required;
use Throwable;

trait LoggerCollectorTrait
{
    protected ?LoggerInterface $logger = null;

    #[Required]
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    protected function processException(Throwable $exception, Metric|string $metric): void
    {
        $name = $metric instanceof Metric ? $metric->name->value : $metric;

        if ($this->logger !== null) {
            $this->logger->error(
                'Cannot save metric "' . $name . '": ' . $exception->getMessage(),
                ['exception' => $exception],
            );
        }
    }
}
