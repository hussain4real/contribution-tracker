<?php

declare(strict_types=1);

it('keeps lint validation non-mutating', function () {
    $contents = file_get_contents(__DIR__.'/../../package.json');

    if ($contents === false) {
        throw new RuntimeException('Unable to read package.json.');
    }

    $package = json_decode(
        $contents,
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $package = is_array($package) ? $package : [];

    $scripts = $package['scripts'] ?? [];
    $scripts = is_array($scripts) ? $scripts : [];

    expect($scripts['lint'] ?? null)
        ->toBe('eslint .')
        ->not->toContain('--fix');

    expect($scripts['lint:fix'] ?? null)
        ->toBe('eslint . --fix');
});
