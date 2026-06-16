<?php

declare(strict_types=1);

it('precaches the root offline fallback used by Workbox navigation handling', function () {
    $contents = file_get_contents(__DIR__.'/../../vite.config.ts');

    if ($contents === false) {
        throw new RuntimeException('Unable to read Vite configuration.');
    }

    expect($contents)
        ->toContain("url: '/offline.html'")
        ->toContain('revision: CACHE_VERSION')
        ->toContain("navigateFallback: '/offline.html'");
});
