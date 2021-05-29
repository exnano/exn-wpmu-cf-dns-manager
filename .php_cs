<?php
return PhpCsFixer\Config::create()
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'no_php4_constructor' => true,
        'no_short_echo_tag' => true,
        'no_useless_else' => true,
        'no_useless_return' => true,
        'no_unreachable_default_argument_value' => true,
        'simplified_null_return' => true,
        'fopen_flags' => ['b_mode' => true],
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'all', 'strict' => true]
    ])
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->in(__DIR__.'/')
            ->exclude('/dist/')
            ->exclude('/wp-plugins-svn/')
            ->exclude('/languages')
    );
