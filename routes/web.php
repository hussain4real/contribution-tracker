<?php

use App\Http\Controllers\AiChatController;
use App\Http\Controllers\Auth\PasskeyLoginController;
use App\Http\Controllers\Auth\PasskeyTwoFactorController;
use App\Http\Controllers\ChangelogController;
use App\Http\Controllers\ContributionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FamilySettingsController;
use App\Http\Controllers\FundAdjustmentController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\MemberPaymentController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaystackWebhookController;
use App\Http\Controllers\PlatformAdminController;
use App\Http\Controllers\PlatformPlanController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Middleware\EnsurePlatformSuperAdmin;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::get('/privacy', fn () => Inertia::render('Legal/Privacy'))->name('privacy');
Route::get('/terms', fn () => Inertia::render('Legal/Terms'))->name('terms');
Route::get('/data-deletion', fn () => Inertia::render('Legal/DataDeletion'))->name('data-deletion');

// Public invitation acceptance
Route::get('invitations/{token}/accept', [InvitationController::class, 'accept'])->name('invitations.accept');

// =========================================================================
// Paystack Webhooks (no CSRF)
// =========================================================================

Route::post('webhooks/paystack', [PaystackWebhookController::class, 'handle'])->name('webhooks.paystack');

// =========================================================================
// Passkey Authentication (Guest)
// =========================================================================

Route::middleware('guest')->group(function () {
    Route::post('passkey/login/options', [PasskeyLoginController::class, 'challengeOptions'])->name('passkey.login.options');
    Route::post('passkey/login', [PasskeyLoginController::class, 'login'])->name('passkey.login');
    Route::post('passkey/two-factor/has-passkeys', [PasskeyTwoFactorController::class, 'hasPasskeys'])->name('passkey.two-factor.has-passkeys');
});

// Passkey 2FA routes (authenticated but not yet 2FA verified)
Route::middleware('auth')->group(function () {
    Route::post('passkey/two-factor/options', [PasskeyTwoFactorController::class, 'challengeOptions'])->name('passkey.two-factor.options');
    Route::post('passkey/two-factor/verify', [PasskeyTwoFactorController::class, 'verify'])->name('passkey.two-factor.verify');
});

// =========================================================================
// Authenticated Routes
// =========================================================================

Route::middleware(['auth', 'verified'])->group(function () {
    // Dashboard
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Notifications
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::patch('notifications/{notification}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');
    Route::post('notifications/mark-all-read', [NotificationController::class, 'markAllAsRead'])->name('notifications.mark-all-read');

    // AI Assistant
    Route::get('ai', [AiChatController::class, 'index'])->name('ai.index');
    Route::post('ai/chat', [AiChatController::class, 'stream'])->name('ai.chat')->middleware('throttle:30,1');
    Route::patch('ai/conversations/{conversation}', [AiChatController::class, 'rename'])->name('ai.conversations.rename');
    Route::delete('ai/conversations/{conversation}', [AiChatController::class, 'destroy'])->name('ai.conversations.destroy');

    // Changelog
    Route::get('changelog', ChangelogController::class)->name('changelog');

    // Members (Admin only for management, all can view list)
    Route::resource('members', MemberController::class)->middleware('subscription');
    Route::post('members/{member}/restore', [MemberController::class, 'restore'])->name('members.restore');

    // Contributions
    Route::get('contributions', [ContributionController::class, 'index'])->name('contributions.index');
    Route::get('contributions/my', [ContributionController::class, 'my'])->name('contributions.my');
    Route::post('contributions/generate', [ContributionController::class, 'generate'])->name('contributions.generate');
    Route::get('contributions/{contribution}', [ContributionController::class, 'show'])->name('contributions.show');

    // Payments
    Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::get('members/{member}/payments/create', [PaymentController::class, 'create'])->name('payments.create');
    Route::post('payments', [PaymentController::class, 'store'])->name('payments.store')
        ->middleware([HandlePrecognitiveRequests::class]);
    Route::delete('payments/{payment}', [PaymentController::class, 'destroy'])->name('payments.destroy');

    // Member Self-Pay (Paystack)
    Route::middleware('subscription:online_payments')->group(function () {
        Route::get('pay', [MemberPaymentController::class, 'show'])->name('pay.index');
        Route::post('pay/initiate', [MemberPaymentController::class, 'initiate'])->name('pay.initiate');
        Route::get('pay/callback', [MemberPaymentController::class, 'callback'])->name('pay.callback');
    });

    // Subscription Management
    Route::get('subscription', [SubscriptionController::class, 'index'])->name('subscription.index');
    Route::post('subscription/subscribe', [SubscriptionController::class, 'subscribe'])->name('subscription.subscribe');
    Route::get('subscription/callback', [SubscriptionController::class, 'callback'])->name('subscription.callback');
    Route::post('subscription/cancel', [SubscriptionController::class, 'cancel'])->name('subscription.cancel');

    // Expenses
    Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses.index');
    Route::get('expenses/create', [ExpenseController::class, 'create'])->name('expenses.create');
    Route::post('expenses', [ExpenseController::class, 'store'])->name('expenses.store')
        ->middleware([HandlePrecognitiveRequests::class]);
    Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');

    // Fund Adjustments (Admin only for create/delete)
    Route::get('fund-adjustments', [FundAdjustmentController::class, 'index'])->name('fund-adjustments.index');
    Route::post('fund-adjustments', [FundAdjustmentController::class, 'store'])->name('fund-adjustments.store')
        ->middleware([HandlePrecognitiveRequests::class]);
    Route::delete('fund-adjustments/{fund_adjustment}', [FundAdjustmentController::class, 'destroy'])->name('fund-adjustments.destroy');

    // Reports (Financial Secretary and Admin only)
    Route::prefix('reports')->name('reports.')->middleware(['can:generate-reports', 'subscription:reports'])->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::get('monthly', [ReportController::class, 'monthly'])->name('monthly');
        Route::get('annual', [ReportController::class, 'annual'])->name('annual');
    });

    // Family Settings (Admin only)
    Route::prefix('family')->name('family.')->group(function () {
        Route::get('settings', [FamilySettingsController::class, 'edit'])->name('settings');
        Route::put('settings', [FamilySettingsController::class, 'update'])->name('settings.update');
        Route::post('categories', [FamilySettingsController::class, 'storeCategory'])->name('categories.store');
        Route::put('categories/{category}', [FamilySettingsController::class, 'updateCategory'])->name('categories.update');
        Route::delete('categories/{category}', [FamilySettingsController::class, 'destroyCategory'])->name('categories.destroy');
        Route::get('banks', [FamilySettingsController::class, 'banks'])->name('banks');

        Route::get('invitations', [InvitationController::class, 'index'])->name('invitations');
        Route::post('invitations', [InvitationController::class, 'store'])->name('invitations.store')->middleware('subscription');
        Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');
    });
});

// =========================================================================
// Platform Super Admin Routes
// =========================================================================

Route::middleware(['auth', 'verified', EnsurePlatformSuperAdmin::class])
    ->prefix('platform')
    ->name('platform.')
    ->group(function () {
        Route::get('/', [PlatformAdminController::class, 'index'])->name('dashboard');
        Route::get('families', [PlatformAdminController::class, 'families'])->name('families');
        Route::get('families/export', [PlatformAdminController::class, 'exportFamilies'])->name('families.export');
        Route::get('families/{family}', [PlatformAdminController::class, 'showFamily'])->name('families.show');
        Route::post('families/{family}/suspend', [PlatformAdminController::class, 'suspendFamily'])->name('families.suspend');
        Route::post('families/{family}/unsuspend', [PlatformAdminController::class, 'unsuspendFamily'])->name('families.unsuspend');
        Route::get('users', [PlatformAdminController::class, 'users'])->name('users');
        Route::get('users/export', [PlatformAdminController::class, 'exportUsers'])->name('users.export');
        Route::post('users/{user}/impersonate', [PlatformAdminController::class, 'impersonate'])->name('users.impersonate');
        Route::post('users/{user}/send-reset', [PlatformAdminController::class, 'sendPasswordReset'])->name('users.send-reset');

        Route::get('plans', [PlatformPlanController::class, 'index'])->name('plans');
        Route::post('plans', [PlatformPlanController::class, 'store'])->name('plans.store');
        Route::put('plans/{plan}', [PlatformPlanController::class, 'update'])->name('plans.update');
        Route::post('plans/{plan}/toggle-active', [PlatformPlanController::class, 'toggleActive'])->name('plans.toggle-active');
        Route::delete('plans/{plan}', [PlatformPlanController::class, 'destroy'])->name('plans.destroy');
    });

// Stop impersonating route — accessible by the impersonated session (not behind super admin middleware)
Route::middleware(['auth'])
    ->post('platform/stop-impersonating', [PlatformAdminController::class, 'stopImpersonating'])
    ->name('platform.stop-impersonating');

require __DIR__.'/settings.php';
