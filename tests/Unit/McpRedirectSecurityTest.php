<?php

declare(strict_types=1);

use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;

it('rejects redirect authority confusion and preserves localhost callback ports', function () {
    $controller = new class extends OAuthRegisterController
    {
        public function validRedirect(string $uri): bool
        {
            return $this->isValidRedirectUri($uri);
        }

        public function localhostRedirect(string $uri): bool
        {
            return $this->isLocalhostUrl($uri);
        }
    };

    foreach (['http://localhost@evil.example/callback', 'http://localhost:3000@evil.example/callback', 'https://user:pass@trusted.example/callback'] as $uri) {
        expect($controller->validRedirect($uri))->toBeFalse();
    }

    foreach (['http://localhost.evil.example/callback', 'http://127.0.0.1.evil.example/callback', 'http://[::1]@evil.example/callback'] as $uri) {
        expect($controller->localhostRedirect($uri))->toBeFalse();
    }

    foreach (['http://localhost:54321/callback', 'http://127.0.0.1:54321/callback', 'http://[::1]:54321/callback'] as $uri) {
        expect($controller->validRedirect($uri))->toBeTrue()
            ->and($controller->localhostRedirect($uri))->toBeTrue();
    }
});
