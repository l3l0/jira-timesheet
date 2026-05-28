<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->files()
    ->in([
        __DIR__ . '/bin',
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->append([
        __FILE__,
    ])
    ->notPath([
        '.env',
        '.env.example',
    ]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setCacheFile('/tmp/php-cs-fixer.cache')
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => [
            'sort_algorithm' => 'alpha',
        ],
        'single_quote' => true,
        'trailing_comma_in_multiline' => [
            'elements' => ['arrays'],
        ],
    ])
    ->setFinder($finder);
