<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Test\Unit\Infrastructure\HttpClient;

use MaxShamaev\MetricsBundle\Infrastructure\HttpClient\PathSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HttpClientDecoratorSanitizeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pathProvider(): iterable
    {
        yield 'empty' => ['', '/'];
        yield 'root' => ['/', '/'];
        yield 'plain path' => ['/users/profile', '/users/profile'];
        yield 'numeric id' => ['/users/123', '/users/:id'];
        yield 'uuid' => ['/orders/550e8400-e29b-41d4-a716-446655440000', '/orders/:uuid'];
        yield 'uuid uppercase' => ['/orders/550E8400-E29B-41D4-A716-446655440000', '/orders/:uuid'];
        yield 'long hex (mongo objectid 24)' => ['/docs/507f1f77bcf86cd799439011', '/docs/:hash'];
        yield 'long hex (32)' => ['/tokens/abcdef0123456789abcdef0123456789', '/tokens/:hash'];
        yield 'mixed segments' => ['/users/42/orders/550e8400-e29b-41d4-a716-446655440000/items', '/users/:id/orders/:uuid/items'];
        yield 'trailing slash preserved' => ['/users/42/', '/users/:id/'];
        yield 'short hex stays' => ['/items/deadbeef', '/items/deadbeef'];
        yield 'slug stays' => ['/articles/2026-q1-report', '/articles/2026-q1-report'];
    }

    #[DataProvider('pathProvider')]
    public function testSanitize(string $input, string $expected): void
    {
        self::assertSame($expected, PathSanitizer::sanitize($input));
    }
}
