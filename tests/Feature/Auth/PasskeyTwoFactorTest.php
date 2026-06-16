<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

test('custom passkey two factor routes are absent', function () {
    expect(Route::has('passkey.two-factor.has-passkeys'))->toBeFalse()
        ->and(Route::has('passkey.two-factor.options'))->toBeFalse()
        ->and(Route::has('passkey.two-factor.verify'))->toBeFalse();
});
