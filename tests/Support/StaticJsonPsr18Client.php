<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Support;

use Nyholm\Psr7\Response;
use Override;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class StaticJsonPsr18Client implements ClientInterface
{
    public const string DOCUMENT = '{"_index":"products","_id":"sku/1","_version":1,"found":true,"_source":{}}';

    #[Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'application/json', 'X-Elastic-Product' => 'Elasticsearch'], self::DOCUMENT);
    }
}
