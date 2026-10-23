<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Support\Cache;

use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * A typed double target: PHPUnit's intersection doubles lose the interfaces for static analysis.
 */
interface ContractsPool extends AdapterInterface, CacheInterface
{
}
