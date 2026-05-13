<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\Catch_\CatchExceptionNameMatchingTypeRector;
use Rector\CodingStyle\Rector\PostInc\PostIncDecToPreIncDecRector;
use Rector\CodingStyle\Rector\Stmt\NewlineAfterStatementRector;
use Rector\Config\RectorConfig;
use Rector\EarlyReturn\Rector\If_\ChangeOrIfContinueToMultiContinueRector;
use Rector\EarlyReturn\Rector\Return_\ReturnBinaryOrToEarlyReturnRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\PHPUnit\AnnotationsToAttributes\Rector\Class_\CoversAnnotationWithValueToAttributeRector;
use Rector\Strict\Rector\Empty_\DisallowedEmptyRuleFixerRector;
use Rector\Symfony\CodeQuality\Rector\ClassMethod\ActionSuffixRemoverRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withoutParallel()
    ->withPhpSets(php84: true)
    ->withComposerBased(doctrine: true, phpunit: true, symfony: true)
    ->withSymfonyContainerPhp(__DIR__ . '/var/cache/dev/App_KernelDevDebugContainer.php')
    ->withAttributesSets(symfony: true, doctrine: true, mongoDb: true, phpunit: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        naming: false,
        instanceOf: true,
        earlyReturn: true,
        carbon: false,
        rectorPreset: true,
        phpunitCodeQuality: false,
        doctrineCodeQuality: true,
        symfonyCodeQuality: true,
        symfonyConfigs: true,
    )
    ->withImportNames(removeUnusedImports: true)
    ->withSkip(
        [
            ClassPropertyAssignToConstructorPromotionRector::class,
            ChangeOrIfContinueToMultiContinueRector::class,
            ReturnBinaryOrToEarlyReturnRector::class,
            PostIncDecToPreIncDecRector::class,
            DisallowedEmptyRuleFixerRector::class,
            NewlineAfterStatementRector::class,
            CatchExceptionNameMatchingTypeRector::class,
            CoversAnnotationWithValueToAttributeRector::class,
            ActionSuffixRemoverRector::class,
        ],
    )
;
