<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\Infrastructure\Doctrine\DBAL\Metrics;

use Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics\QueryLabeller;
use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\DoctrineQueryTypeEnum;
use PHPUnit\Framework\TestCase;

final class QueryLabellerTest extends TestCase
{
    public function testRepeatedStatementIsServedFromCache(): void
    {
        $labeller = new QueryLabeller();

        $labels = $labeller->label('SELECT * FROM users');

        self::assertSame(DoctrineQueryTypeEnum::SELECT, $labels->type);
        self::assertSame('users', $labels->table);
        self::assertSame($labels, $labeller->label('SELECT * FROM users'));
    }

    public function testStatementsSharingTheParsedPrefixShareLabels(): void
    {
        $labeller = new QueryLabeller();
        $prefix = 'SELECT * FROM users WHERE id IN (' . str_repeat('1, ', 6_000);

        self::assertSame($labeller->label($prefix . '2)'), $labeller->label($prefix . '3)'));
    }

    public function testEvictsTheLeastRecentlyUsedStatement(): void
    {
        $labeller = new QueryLabeller();
        $kept = $labeller->label('SELECT * FROM kept');
        $evicted = $labeller->label('SELECT * FROM evicted');
        for ($i = 0; $i < 254; $i++) {
            $labeller->label('SELECT * FROM t' . $i);
        }

        self::assertSame($kept, $labeller->label('SELECT * FROM kept'));
        $labeller->label('SELECT * FROM overflow');

        self::assertSame($kept, $labeller->label('SELECT * FROM kept'));
        $relabelled = $labeller->label('SELECT * FROM evicted');
        self::assertNotSame($evicted, $relabelled);
        self::assertSame('evicted', $relabelled->table);
    }
}
