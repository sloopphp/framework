<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'declare_strict_types' => true,
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced'],
        'no_unused_imports' => true,
        'phpdoc_add_missing_param_annotation' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'phpdoc_separation' => ['groups' => [['template', 'param', 'return', 'throws']]],
        'single_quote' => true,
        'strict_comparison' => true,
        'trailing_comma_in_multiline' => true,
    ])
    ->setFinder($finder);
