<?php

use App\Http\Controllers\Settings\PasskeyController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\TwoFactorAuthenticationController;
use App\Http\Controllers\Settings\WhatsAppVerificationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::post('settings/whatsapp/send-code', [WhatsAppVerificationController::class, 'sendCode'])
        ->middleware('throttle:3,5')
        ->name('whatsapp.send-code');
    Route::post('settings/whatsapp/verify', [WhatsAppVerificationController::class, 'verifyCode'])
        ->middleware('throttle:6,5')
        ->name('whatsapp.verify');
    Route::delete('settings/whatsapp', [WhatsAppVerificationController::class, 'destroy'])
        ->name('whatsapp.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('user-password.edit');

    Route::put('settings/password', [PasswordController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/Appearance');
    })->name('appearance.edit');

    Route::get('settings/two-factor', [TwoFactorAuthenticationController::class, 'show'])
        ->name('two-factor.show');

    Route::get('settings/passkeys', [PasskeyController::class, 'show'])->name('passkeys.show');
    Route::post('settings/passkeys/options', [PasskeyController::class, 'createOptions'])->name('passkeys.create-options');
    Route::post('settings/passkeys', [PasskeyController::class, 'store'])->name('passkeys.store');
    Route::delete('settings/passkeys/{passkey}', [PasskeyController::class, 'destroy'])->name('passkeys.destroy');
});
