<?php

/**
 * Code style. Based on GLPI core's .php-cs-fixer.dist.php (11.0/bugfixes and 12.0/bugfixes, identical rules),
 * with the PHP migration set lowered to 8.2: the plugin must run on GLPI 11, which supports PHP 8.2.
 */

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in(__DIR__)
    ->exclude(['vendor', 'node_modules', 'tests'])
    ->name('*.php');

return (new Config())
    ->setRules([
        '@PER-CS2.0'                   => true,
        '@PHP82Migration'              => true,
        'fully_qualified_strict_types' => ['import_symbols' => true],
        'ordered_imports'              => ['imports_order' => ['class', 'const', 'function']],
        'no_unused_imports'            => true,
        'heredoc_indentation'          => false,
        'phpdoc_scalar'                => true,
        'phpdoc_types'                 => true,
    ])
    ->setFinder($finder)
    ->setUsingCache(false);
