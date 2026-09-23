<?php
// Rector config for the kit. Paths come from the CLI (bin/php-fix.sh passes them).
// Stage is selected with env KIT_RECTOR_STAGE:
//   safe  -> rules whose output also runs on PHP 8.3 (apply while still on 2.4.7-p10 / 8.3).
//            PhpVersion is 8.4 only so Rector enables these rules; the output is 7.1/8.0 syntax.
//   php84 -> SetList::PHP_84 (only once the runtime is 8.4: emits 8.4-only syntax/enums)
//   php85 -> SetList::PHP_85 (only once the runtime is 8.5: array_first(), Pdo\Mysql, ...)
use Rector\Config\RectorConfig;
use Rector\Php84\Rector\FuncCall\AddEscapeArgumentRector;
use Rector\Php84\Rector\Param\ExplicitNullableParamTypeRector;
use Rector\Set\ValueObject\SetList;
use Rector\ValueObject\PhpVersion;

$stage = getenv('KIT_RECTOR_STAGE') ?: 'safe';

$config = RectorConfig::configure()
    ->withFileExtensions(['php', 'phtml'])
    ->withSkip([
        '*/Test/*', '*/tests/*',
        // ponytail: skipped as too risky for Magento (parent AbstractModel/DataObject define __sleep/__wakeup;
        // a child __serialize silently disables them). Review 8.5 __sleep/__wakeup soft-deprecation by hand.
        \Rector\Php85\Rector\Class_\SleepToSerializeRector::class,
        \Rector\Php85\Rector\Class_\WakeupToUnserializeRector::class,
        \Rector\Php85\Rector\Property\AddOverrideAttributeToOverriddenPropertiesRector::class,
        // style-only, not needed for compatibility
        \Rector\Php84\Rector\MethodCall\NewMethodCallWithoutParenthesesRector::class,
        \Rector\Php84\Rector\Foreach_\ForeachToArrayFindRector::class,
        \Rector\Php84\Rector\Foreach_\ForeachToArrayFindKeyRector::class,
        \Rector\Php84\Rector\Foreach_\ForeachToArrayAllRector::class,
        \Rector\Php84\Rector\Foreach_\ForeachToArrayAnyRector::class,
        \Rector\Php85\Rector\ArrayDimFetch\ArrayFirstLastRector::class,
    ]);

// Magento autoload lets Rector resolve parent/interface types; optional.
if ($autoload = getenv('KIT_AUTOLOAD')) {
    $config->withAutoloadPaths([$autoload]);
}

return match ($stage) {
    'safe'  => $config->withPhpVersion(PhpVersion::PHP_84)->withRules([ExplicitNullableParamTypeRector::class, AddEscapeArgumentRector::class]),
    'php84' => $config->withPhpVersion(PhpVersion::PHP_84)->withSets([SetList::PHP_84]),
    'php85' => $config->withPhpVersion(PhpVersion::PHP_85)->withSets([SetList::PHP_85]),
};
