<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\DependencyInjection\Fixture;

use Msstc4Symfony\MetricsBundle\Infrastructure\HttpClient\URLAssembler\AssemblerInterface;
use Override;

final readonly class StaticUrlAssembler implements AssemblerInterface
{
    #[Override]
    public function assemble(string $url): array
    {
        return ['static-host', '/static-path'];
    }
}
