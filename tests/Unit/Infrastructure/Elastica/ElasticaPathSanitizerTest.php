<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Infrastructure\Elastica;

use Msstc4Symfony\MetricsBundle\Infrastructure\Elastica\ElasticaPathSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ElasticaPathSanitizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pathProvider(): iterable
    {
        yield 'doc with string id' => ['products/_doc/sku-abc', 'products/_doc/:id'];
        yield 'doc with numeric id' => ['products/_doc/42', 'products/_doc/:id'];
        yield 'doc with uuid id' => ['products/_doc/550e8400-e29b-41d4-a716-446655440000', 'products/_doc/:id'];
        yield 'doc with url-encoded id' => ['products/_doc/a%2Fb%20c', 'products/_doc/:id'];
        yield 'doc with leading slash' => ['/products/_doc/sku-abc', '/products/_doc/:id'];
        yield 'create' => ['products/_create/sku-abc', 'products/_create/:id'];
        yield 'update' => ['products/_update/sku-abc', 'products/_update/:id'];
        yield 'source' => ['products/_source/sku-abc', 'products/_source/:id'];
        yield 'explain' => ['products/_explain/sku-abc', 'products/_explain/:id'];
        yield 'termvectors' => ['products/_termvectors/sku-abc', 'products/_termvectors/:id'];
        yield 'doc source suffix' => ['products/_doc/sku-abc/_source', 'products/_doc/:id/_source'];
        yield 'id named like an endpoint' => ['products/_doc/_update', 'products/_doc/:id'];
        yield 'index name kept' => ['logs-2026.10.06/_doc/abc', 'logs-2026.10.06/_doc/:id'];
        yield 'doc without id' => ['products/_doc', 'products/_doc'];
        yield 'doc with trailing slash' => ['products/_doc/', 'products/_doc/'];
        yield 'termvectors without id' => ['products/_termvectors', 'products/_termvectors'];
        yield 'endpoint without index' => ['_doc/abc', '_doc/abc'];
        yield 'endpoint without index, leading slash' => ['/_doc/abc', '/_doc/abc'];
        yield 'search' => ['products/_search', 'products/_search'];
        yield 'bulk' => ['_bulk', '_bulk'];
        yield 'index bulk' => ['/products/_bulk', '/products/_bulk'];
        yield 'cluster health' => ['_cluster/health', '_cluster/health'];
        yield 'update by query' => ['products/_update_by_query', 'products/_update_by_query'];
        yield 'empty' => ['', ''];
        yield 'root' => ['/', '/'];
        yield 'generic id rules still apply' => ['_tasks/1234', '_tasks/:id'];
        yield 'generic uuid rule' => ['_snapshot/repo/550e8400-e29b-41d4-a716-446655440000', '_snapshot/repo/:uuid'];
    }

    #[DataProvider('pathProvider')]
    public function testSanitize(string $input, string $expected): void
    {
        self::assertSame($expected, ElasticaPathSanitizer::sanitize($input));
    }
}
