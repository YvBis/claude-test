<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude('var')
    ->exclude('vendor')
    ->exclude('public')
    ->notPath([
        'config/bundles.php',
        'config/reference.php',
        'composer-setup.php',
    ])
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        '@PSR12' => true,
        '@PHP83Migration' => true,
        'strict_param' => true,
        'return_type_declaration' => ['space_before' => 'none'],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'no_trailing_whitespace' => true,
        'blank_line_after_namespace' => true,
        'single_line_throw' => false,
        'phpdoc_order' => true,
        'phpdoc_summary' => false,
        'void_return' => true,
        'native_function_invocation' => ['include' => ['@all']],
        'modernize_types_casting' => true,
        'lambda_not_used_import' => true,
        // Second line of defence for line endings: .gitattributes already
        // normalizes every checkout to LF, but php-cs-fixer reads raw bytes, so
        // a file that picked up CRLF outside git would otherwise be reported as
        // a whole-file diff instead of a one-line fix. The fixer normalizes to
        // LF and takes no options (3.95), and it is risky - the project already
        // runs with --allow-risky=yes.
        'line_ending' => true,
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/var/cache/.php-cs-fixer.cache')
;