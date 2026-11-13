<?php

require_once __DIR__.'/vendor/autoload.php';

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__.'/config',
        __DIR__.'/lang',
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->append([__FILE__])
    ->notName('*.blade.php');

return FullSmack\PhpCsFixerBreathe\Config::create()
    ->setFinder($finder);
