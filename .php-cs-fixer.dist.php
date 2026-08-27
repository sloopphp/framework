<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/.claude',
    ])
    // A git worktree under .claude is a whole second checkout, vendor included,
    // and a parallel session writes into it while this runs. Left in, the scan
    // grows from 198 files to five figures and reads half-written files.
    ->exclude('worktrees')
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
        'phpdoc_align' => ['align' => 'vertical'],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'phpdoc_separation' => ['groups' => [['template', 'param', 'return', 'throws']]],
        'single_quote' => true,
        'strict_comparison' => true,
        'trailing_comma_in_multiline' => true,
    ])
    ->setFinder($finder);
