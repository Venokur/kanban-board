<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/backend/src')
    ->name('*.php')
    ->notName('rt.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'trailing_comma_in_multiline' => [
            'elements' => ['arrays', 'arguments', 'parameters'],
        ],
        'concat_space' => ['spacing' => 'one'],
        // The codebase writes `fn(array $row)` and keeps long SQL in
        // double quotes for readability; both are style choices, not mistakes.
        'function_declaration' => ['closure_fn_spacing' => 'none'],
        'single_quote' => false,
    ])
    ->setFinder($finder);
