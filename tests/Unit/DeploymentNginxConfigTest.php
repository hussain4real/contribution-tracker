<?php

declare(strict_types=1);

it('passes Livewire hashed asset routes to Laravel before static asset handling', function () {
    $contents = file_get_contents(__DIR__.'/../../deployment/nginx/familyfunds.app.conf');

    if ($contents === false) {
        throw new RuntimeException('Unable to read production Nginx configuration.');
    }

    $livewireLocation = strpos($contents, 'location ^~ /livewire-');
    $staticAssetLocation = strpos($contents, 'location ~* \.(css|js|gif|ico|jpg|jpeg|png|svg|webp|woff|woff2|ttf|eot)$');

    expect($livewireLocation)->not->toBeFalse()
        ->and($staticAssetLocation)->not->toBeFalse()
        ->and($contents)->toContain('location ^~ /livewire-')
        ->and($contents)->toContain('try_files $uri $uri/ /index.php?$query_string;');

    if ($livewireLocation === false || $staticAssetLocation === false) {
        throw new RuntimeException('Expected production Nginx config to contain Livewire and static asset locations.');
    }

    expect($livewireLocation)->toBeLessThan($staticAssetLocation);
});
