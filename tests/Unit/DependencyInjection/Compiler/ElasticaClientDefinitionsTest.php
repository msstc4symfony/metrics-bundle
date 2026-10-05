<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Unit\DependencyInjection\Compiler;

use ArrayIterator;
use Msstc4Symfony\MetricsBundle\DependencyInjection\Compiler\ElasticaClientDefinitions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

#[CoversClass(ElasticaClientDefinitions::class)]
final class ElasticaClientDefinitionsTest extends TestCase
{
    public function testFollowsAParentGivenAsAlias(): void
    {
        $container = new ContainerBuilder();
        $prototype = new Definition(ArrayIterator::class);
        $container->setDefinition('prototype', $prototype);
        $container->setAlias('prototype.alias', new Alias('prototype'));

        $leaf = new ChildDefinition('prototype.alias');

        self::assertSame([$leaf, $prototype], [...ElasticaClientDefinitions::lineage($container, $leaf)]);
    }

    public function testStopsWithoutThrowingWhenTheParentAliasPointsToAMissingService(): void
    {
        $container = new ContainerBuilder();
        $container->setAlias('prototype.alias', new Alias('missing'));

        $leaf = new ChildDefinition('prototype.alias');

        self::assertSame([$leaf], [...ElasticaClientDefinitions::lineage($container, $leaf)]);
    }
}
