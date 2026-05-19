<?php

it('keeps lint validation non-mutating', function () {
    $package = json_decode(
        file_get_contents(__DIR__.'/../../package.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $scripts = $package['scripts'] ?? [];

    expect($scripts['lint'] ?? null)
        ->toBe('eslint .')
        ->not->toContain('--fix');

    expect($scripts['lint:fix'] ?? null)
        ->toBe('eslint . --fix');
});
