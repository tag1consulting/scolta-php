<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests')
    // Vendored generated code (see src/Index/Snowball/PROVENANCE.md) must stay
    // byte-identical to its sha256 manifest — never reformat it.
    ->exclude('Index/Snowball');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS' => true,
        'strict_param' => true,
        'declare_strict_types' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(true);
