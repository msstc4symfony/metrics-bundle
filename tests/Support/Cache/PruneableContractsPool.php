<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Support\Cache;

use Symfony\Component\Cache\PruneableInterface;

interface PruneableContractsPool extends ContractsPool, PruneableInterface
{
}
