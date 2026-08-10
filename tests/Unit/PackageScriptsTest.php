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

it('configures Pest 5 with local TIA and an unchanged full coverage gate', function () {
    $composerContents = file_get_contents(__DIR__.'/../../composer.json');
    $pestContents = file_get_contents(__DIR__.'/../Pest.php');

    if ($composerContents === false || $pestContents === false) {
        throw new RuntimeException('Unable to read the Pest configuration files.');
    }

    $composer = json_decode($composerContents, true, 512, JSON_THROW_ON_ERROR);
    $composer = is_array($composer) ? $composer : [];
    $requirements = is_array($composer['require'] ?? null) ? $composer['require'] : [];
    $developmentRequirements = is_array($composer['require-dev'] ?? null) ? $composer['require-dev'] : [];
    $scripts = is_array($composer['scripts'] ?? null) ? $composer['scripts'] : [];
    $ciCheck = is_array($scripts['ci:check'] ?? null) ? $scripts['ci:check'] : [];
    $freshCoverageTia = is_array($scripts['test:coverage:tia:fresh'] ?? null)
        ? $scripts['test:coverage:tia:fresh']
        : [];

    expect($requirements['php'] ?? null)->toBe('^8.4')
        ->and($developmentRequirements['pestphp/pest'] ?? null)->toBe('^5.0')
        ->and($developmentRequirements['pestphp/pest-plugin-agent'] ?? null)->toBe('^5.0')
        ->and($developmentRequirements['pestphp/pest-plugin-phpstan'] ?? null)->toBe('^5.0')
        ->and($scripts['test:tia'] ?? null)->toContain('--tia')
        ->and($scripts['test:tia'] ?? null)->toContain('--passthru-php')
        ->and($scripts['test:tia:fresh'] ?? null)->toContain('--tia --fresh')
        ->and($scripts['test:tia:fresh'] ?? null)->toContain('--passthru-php')
        ->and($scripts['test:tia:shared'] ?? null)->toContain('--tia --baselined')
        ->and($scripts['test:tia:shared'] ?? null)->toContain('--passthru-php')
        ->and($scripts['test:coverage:tia'] ?? null)->toContain('--coverage')
        ->and($scripts['test:coverage:tia'] ?? null)->toContain('--min=100')
        ->and($scripts['test:coverage:tia'] ?? null)->toContain('--tia')
        ->and($scripts['test:coverage:tia'] ?? null)->toContain('--passthru-php')
        ->and($freshCoverageTia)->toBe(['@test:tia:fresh', '@test:coverage:tia'])
        ->and($scripts['test:coverage'] ?? null)->toContain('--min=100')
        ->and($scripts['test:coverage'] ?? null)->toContain('--no-tia')
        ->and($scripts['test:coverage'] ?? null)->toContain('--passthru-php')
        ->and($scripts['test:coverage'] ?? null)->not->toContain('--tia')
        ->and($ciCheck)->toContain('@test:coverage')
        ->and($ciCheck)->not->toContain('@test:coverage:tia')
        ->and($pestContents)->toContain('->tia()')
        ->and($pestContents)->toContain('->locally()')
        ->and($pestContents)->toContain('->baselined()')
        ->and($pestContents)->not->toContain('pest()->tia()->always();');
});
