<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\HttpClient\URLAssembler;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('metrics.htp_client.url_assembler')]
interface AssemblerInterface
{
    /**
     * @return array{string, string}|null
     */
    public function assemble(string $url): ?array;
}
