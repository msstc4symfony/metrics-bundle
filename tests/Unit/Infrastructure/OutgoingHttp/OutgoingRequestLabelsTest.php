<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Infrastructure\OutgoingHttp;

use Msstc4Symfony\MetricsBundle\Infrastructure\HttpClient\URLAssembler\AssemblerInterface;
use Msstc4Symfony\MetricsBundle\Infrastructure\OutgoingHttp\OutgoingRequestLabels;
use Override;
use PHPUnit\Framework\TestCase;

final class OutgoingRequestLabelsTest extends TestCase
{
    public function testHostAndSanitizedPathOfTheUrl(): void
    {
        self::assertSame(['api.example.com', '/users/:id'], (new OutgoingRequestLabels([], true))->of('https://api.example.com/users/42?x=1'));
    }

    public function testRawPathWhenSanitizingIsOff(): void
    {
        self::assertSame(['api.example.com', '/users/42'], (new OutgoingRequestLabels([], false))->of('https://api.example.com/users/42'));
    }

    public function testUrlWithoutHostOrPath(): void
    {
        $labels = new OutgoingRequestLabels([], true);

        self::assertSame(['', '/'], $labels->of('http:///'));
        self::assertSame(['', '/v1/:id'], $labels->of('/v1/42'));
        self::assertSame(['api.example.com', '/'], $labels->of('https://api.example.com'));
        self::assertSame(['api.example.com', '/'], $labels->of('https://api.example.com?q=1'));
    }

    public function testFirstMatchingAssemblerWins(): void
    {
        $labels = new OutgoingRequestLabels([$this->assembler(null), $this->assembler(['first', '/1']), $this->assembler(['second', '/2'])], true);

        self::assertSame(['first', '/1'], $labels->of('https://api.example.com/users/42'));
    }

    public function testAssemblerResultIsNotSanitized(): void
    {
        self::assertSame(['h', '/users/42'], (new OutgoingRequestLabels([$this->assembler(['h', '/users/42'])], true))->of('https://api.example.com/x'));
    }

    /**
     * @param array{string, string}|null $result
     */
    private function assembler(?array $result): AssemblerInterface
    {
        return new class($result) implements AssemblerInterface {
            /**
             * @param array{string, string}|null $result
             */
            public function __construct(
                private readonly ?array $result,
            ) {
            }

            #[Override]
            public function assemble(string $url): ?array
            {
                return $this->result;
            }
        };
    }
}
