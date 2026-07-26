<?php

declare(strict_types=1);

use App\Http\Controllers\FamilySwitchController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\WebPushSubscriptionController;
use App\Http\Controllers\Settings\WhatsAppVerificationController;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('settings/password', fn () => abort(404));
Route::get('settings/two-factor', fn () => abort(404));
Route::get('settings/passkeys', fn () => abort(404));

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

    Route::post('settings/web-push/subscription', [WebPushSubscriptionController::class, 'store'])
        ->middleware([Authenticate::class, 'throttle:6,1'])
        ->name('web-push.subscription.store');
    Route::delete('settings/web-push/subscription', [WebPushSubscriptionController::class, 'destroy'])
        ->middleware(Authenticate::class)
        ->name('web-push.subscription.destroy');

    Route::post('settings/families/{family:slug}/switch', FamilySwitchController::class)
        ->name('families.switch');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/Appearance');
    })->name('appearance.edit');

});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
