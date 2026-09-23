<?php
// Adobe Commerce 2.4.8 -> 2.4.9 breaking changes. Sources: 2.4.9 release notes, BIC reference 2-4-8-to-2-4-9,
// symfony/console 7.4 Command.php (execute(): int, isEnabled(): bool; untyped in 6.4).
return [
    // --- Symfony 6.4 -> 7.4. Fixes below are also valid on 6.4, so they can ship before the upgrade.
    ['id' => 'm249.symfony.execute-int', 'level' => 'fatal',
     'find' => '/(protected|public)(\s+function\s+execute\s*\(\s*(?:\\\\?Symfony\\\\Component\\\\Console\\\\Input\\\\)?InputInterface\s+\$\w+\s*,\s*(?:\\\\?Symfony\\\\Component\\\\Console\\\\Output\\\\)?OutputInterface\s+\$\w+\s*\))(?!\s*:)/',
     'note' => 'Symfony 7: Command::execute() must declare : int (make sure every path returns an int, e.g. Command::SUCCESS)',
     'fix' => '$1$2: int'],
    ['id' => 'm249.symfony.isEnabled-bool', 'level' => 'fatal',
     'find' => '/(public\s+function\s+isEnabled\s*\(\s*\))(?!\s*:)/',
     'when' => '/extends\s+\\\\?(Symfony\\\\Component\\\\Console\\\\Command\\\\)?Command\b/',
     'note' => 'Symfony 7: Command::isEnabled(): bool', 'fix' => '$1: bool'],
    ['id' => 'm249.symfony.defaultName', 'level' => 'fatal',
     'find' => '/protected\s+static\s+\$default(Name|Description)\b/',
     'note' => 'Symfony 7 removed Command::$defaultName/$defaultDescription -> #[AsCommand] or setName() in configure()'],
    ['id' => 'm249.symfony.extends', 'level' => 'review',
     'find' => '/extends\s+\\\\?Symfony\\\\(?!Component\\\\Console\\\\Command\\\\Command\b)\S+/',
     'note' => 'Custom class extending a Symfony class: re-check signatures against 7.4'],

    // --- Zend_Cache -> symfony/cache
    ['id' => 'm249.zend-cache', 'level' => 'fatal',
     'find' => '/\bZend_Cache\w*|\\\\Zend_Cache/',
     'note' => 'Zend_Cache replaced by symfony/cache in 2.4.9 (constants like Zend_Cache::CLEANING_MODE_MATCHING_TAG gone). Use \Magento\Framework\Cache\FrontendInterface / tag-based clean()'],
    ['id' => 'm249.cache-backend', 'level' => 'review',
     'find' => '/->(getBackend|getLowLevelFrontend)\s*\(\)|Cm_Cache_Backend_\w+|Magento\\\\Framework\\\\Cache\\\\Backend\\\\/',
     'note' => 'Cache FrontendInterface::getBackend/getLowLevelFrontend return types changed; Zend backends replaced'],

    // --- Laminas MVC -> native MVC, laminas-db/magento-zf-db -> php-db/phpdb, oauth lib removed
    ['id' => 'm249.laminas', 'level' => 'review',
     'find' => '/\b(Laminas|Zend)\\\\\w+/',
     'note' => '2.4.9 dropped laminas-mvc / magento-zf-mvc / magento-zf-db. Each Laminas\* class used must still exist in vendor after composer update (setup:di:compile + lint of project-only vendor dirs will show it)'],
    ['id' => 'm249.oauth-lib', 'level' => 'fatal',
     'find' => '/\bOAuth\\\\(Common|OAuth1|OAuth2)\\\\/', 'note' => 'carlos-mg89/oauth (lusitanian fork) removed in 2.4.9'],
    ['id' => 'm249.jose', 'level' => 'review',
     'find' => '/\bJose\\\\/', 'note' => 'web-token/jwt-framework ^3 -> ^4'],
    ['id' => 'm249.emogrifier', 'level' => 'review',
     'find' => '/\bPelago\\\\Emogrifier/', 'note' => 'pelago/emogrifier ^7 -> ^8'],

    // --- BIC reference 2.4.8 -> 2.4.9
    ['id' => 'm249.sig.return-typed', 'level' => 'review',
     'find' => '/function\s+(escapeHtml|createBlock|addBlock|getBlockSingleton|doRun|_loadPrimaryConfig|setCache|getCache)\s*\(/',
     'note' => 'Return/param typing changed in 2.4.9 (Escaper/AbstractBlock::escapeHtml, BlockFactory/Layout createBlock, Cli::doRun, CurrencyInterface cache)'],
    ['id' => 'm249.removed-methods', 'level' => 'review',
     'find' => '/Magento\\\\Backend\\\\Block\\\\Widget\\\\Breadcrumbs|Adminhtml\\\\Order\\\\Create\\\\Newsletter/',
     'note' => 'Breadcrumbs::_beforeToHtml and Order\Create\Newsletter::_toHtml removed'],
    ['id' => 'm249.user-validation-rules', 'level' => 'review',
     'find' => '/UserValidationRules/', 'note' => 'Magento\User\Model\UserValidationRules got a constructor (admin min password length)'],
    ['id' => 'm249.removed-config', 'level' => 'review', 'files' => '/\.(php|xml)$/', 'skipComments' => false,
     'find' => '/catalog\/rule\/share_|graphql_share_all_customer_groups|share_segments_list|promo\/graphql\/share_/',
     'note' => 'system config fields removed in 2.4.9'],
];
