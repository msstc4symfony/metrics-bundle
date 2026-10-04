<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Infrastructure\HttpClient\URLAssembler;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(self::TAG)]
interface AssemblerInterface
{
    public const string TAG = 'metrics.http_client.url_assembler';

    /**
     * @return array{string, string}|null
     */
    public function assemble(string $url): ?array;
}
