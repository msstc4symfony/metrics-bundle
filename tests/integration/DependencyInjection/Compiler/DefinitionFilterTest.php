<?php

declare(strict_types=1);

namespace MaxShamaev\MetricsBundle\Test\Integration\DependencyInjection\Compiler;

use MaxShamaev\MetricsBundle\DependencyInjection\Compiler\DefinitionFilter;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\Definition;

final class DefinitionFilterTest extends TestCase
{
    public function testRegularDefinitionIsDecoratable(): void
    {
        self::assertTrue(DefinitionFilter::isDecoratable(new Definition(stdClass::class)));
    }

    public function testAbstractDefinitionIsNotDecoratable(): void
    {
        $definition = new Definition(stdClass::class);
        $definition->setAbstract(true);

        self::assertFalse(DefinitionFilter::isDecoratable($definition));
    }

    public function testAlreadyDecoratedDefinitionIsNotDecoratable(): void
    {
        $definition = new Definition(stdClass::class);
        $definition->setDecoratedService('some.service');

        self::assertFalse(DefinitionFilter::isDecoratable($definition));
    }
}
