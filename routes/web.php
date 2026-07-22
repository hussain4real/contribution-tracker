<?php

declare(strict_types=1);

use App\Features\AiAssistant;
use App\Http\Controllers\AiChatController;
use App\Http\Controllers\ChangelogController;
use App\Http\Controllers\ChangelogSeenController;
use App\Http\Controllers\ContributionController;
use App\Http\Controllers\ContributionEmailReminderController;
use App\Http\Controllers\ContributionWebPushReminderController;
use App\Http\Controllers\ContributionWhatsAppReminderController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ExpenseReversalController;
use App\Http\Controllers\FamilyArchiveController;
use App\Http\Controllers\FamilySettingsController;
use App\Http\Controllers\FundAdjustmentController;
use App\Http\Controllers\FundAdjustmentReversalController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\MemberPaymentController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentBatchReversalController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentReceiptController;
use App\Http\Controllers\PaystackWebhookController;
use App\Http\Controllers\PlatformAdminController;
use App\Http\Controllers\PricingController;
use App\Http\Controllers\ProviderSettlementGroupController;
use App\Http\Controllers\ReconciliationController;
use App\Http\Controllers\ReconciliationImportController;
use App\Http\Controllers\ReconciliationLinkController;
use App\Http\Controllers\ReconciliationPeriodController;
use App\Http\Controllers\ReconciliationTransactionStatusController;
use App\Http\Controllers\ReportArtifactController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\ReportScheduleController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\WhatsAppInboxController;
use App\Http\Controllers\WhatsAppWebhookController;
use App\Models\Family;
use App\Models\PlatformPlan;
use App\Models\User;
use App\Support\PlatformPlanCatalog;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Pennant\Middleware\EnsureFeaturesAreActive;

Route::get('/', function () {
    $pricingPreviewPlans = PlatformPlan::query()
        ->where('is_active', true)
        ->orderBy('sort_order')
        ->get()
        ->map(fn (PlatformPlan $plan): array => PlatformPlanCatalog::subscriptionCard($plan));

    return Inertia::render('Welcome', [
        'canRegister' => Features::enabled(Features::registration()),
        'pricingPreviewPlans' => $pricingPreviewPlans,
        'availableFeatures' => PlatformPlanCatalog::featureLabels(),
    ]);
})->name('home');

Route::get('/pricing', PricingController::class)->name('pricing');
Route::get('/privacy', fn () => Inertia::render('Legal/Privacy'))->name('privacy');
Route::get('/terms', fn () => Inertia::render('Legal/Terms'))->name('terms');
Route::get('/data-deletion', fn () => Inertia::render('Legal/DataDeletion'))->name('data-deletion');

// Public invitation acceptance
Route::get('invitations/{token}/accept', [InvitationController::class, 'accept'])->name('invitations.accept');

// =========================================================================
// Paystack Webhooks (no CSRF)
// =========================================================================

Route::post('webhooks/paystack', [PaystackWebhookController::class, 'handle'])->name('webhooks.paystack');

Route::get('webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify'])->name('webhooks.whatsapp.verify');
Route::post('webhooks/whatsapp', [WhatsAppWebhookController::class, 'handle'])->name('webhooks.whatsapp');

Route::get('report-deliveries/{reportDelivery}', [ReportArtifactController::class, 'delivery'])
    ->name('reports.deliveries.download');

// =========================================================================
// Platform Super Admin Routes
// =========================================================================

Route::redirect('platform/families/export', 'platform/families')->name('platform.families.export');
Route::redirect('platform/users/export', 'platform/users')->name('platform.users.export');

// Stop impersonating route — accessible by the impersonated session (not behind super admin middleware)
Route::middleware(['auth'])
    ->post('platform/stop-impersonating', [PlatformAdminController::class, 'stopImpersonating'])
    ->name('platform.stop-impersonating');

require __DIR__.'/settings.php';

Route::middleware(['auth', 'verified'])->get('dashboard', function (Request $request) {
    $user = $request->user();

    abort_unless($user instanceof User, 403);

    $family = $user->currentFamily
        ?? $user->family
        ?? $user->families()->orderByRaw('LOWER(families.name)')->first();

    if (! $family instanceof Family) {
        return redirect()->route('home');
    }

    $membership = $user->membershipForFamily($family);

    if ($membership !== null && ($user->current_family_id !== $family->id || $user->family_id !== $family->id)) {
        $user->switchFamily($family, $membership);
    }

    return redirect()->route('dashboard', ['current_family' => $family->slug]);
})->name('legacy.dashboard');

// =========================================================================
// Family-Scoped Authenticated Routes
// =========================================================================

Route::prefix('{current_family}')
    ->where([
        'current_family' => '^(?!settings$|platform$|pricing$|privacy$|terms$|data-deletion$|webhooks$|invitations$|login$|logout$|register$|forgot-password$|reset-password$|email$|passkeys$|user$|oauth$|mcp$|up$)[A-Za-z0-9-]+$',
    ])
    ->middleware(['auth', 'verified', 'family.member', 'family.active'])
    ->group(function () {
        // Dashboard
        Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

        // Notifications
        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::patch('notifications/{notification}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');
        Route::post('notifications/mark-all-read', [NotificationController::class, 'markAllAsRead'])->name('notifications.mark-all-read');

        // WhatsApp inbox (Admin / Financial Secretary only — role gate enforced in controller)
        Route::middleware('subscription:'.PlatformPlanCatalog::WhatsappMessaging)->group(function () {
            Route::get('inbox/whatsapp', [WhatsAppInboxController::class, 'index'])->name('inbox.whatsapp.index');
            Route::get('inbox/whatsapp/{phone}', [WhatsAppInboxController::class, 'show'])
                ->where('phone', '[0-9]+')
                ->name('inbox.whatsapp.show');
            Route::post('inbox/whatsapp/{phone}/reply', [WhatsAppInboxController::class, 'reply'])
                ->where('phone', '[0-9]+')
                ->middleware('throttle:30,1')
                ->name('inbox.whatsapp.reply');
        });

        // AI Assistant (gated by subscription plan and Pennant feature flag)
        Route::middleware([
            'subscription:'.PlatformPlanCatalog::AiAssistant,
            EnsureFeaturesAreActive::using(AiAssistant::class),
        ])->group(function () {
            Route::get('ai', [AiChatController::class, 'index'])->name('ai.index');
            Route::post('ai/chat', [AiChatController::class, 'stream'])->name('ai.chat')->middleware('throttle:30,1');
            Route::post('ai/transcribe', [AiChatController::class, 'transcribe'])->name('ai.transcribe')->middleware('throttle:20,1');
            Route::patch('ai/conversations/{conversation}', [AiChatController::class, 'rename'])->name('ai.conversations.rename');
            Route::delete('ai/conversations/{conversation}', [AiChatController::class, 'destroy'])->name('ai.conversations.destroy');
        });

        // Changelog
        Route::get('changelog', ChangelogController::class)->name('changelog');
        Route::post('changelog/seen', ChangelogSeenController::class)->name('changelog.seen');

        // Members (Admin only for management, all can view list)
        Route::resource('members', MemberController::class)->middleware('subscription');
        Route::post('members/{member}/restore', [MemberController::class, 'restore'])->name('members.restore');

        // Contributions
        Route::get('contributions', [ContributionController::class, 'index'])->name('contributions.index');
        Route::get('contributions/my', [ContributionController::class, 'my'])->name('contributions.my');
        Route::get('contributions/my/statement', ReportExportController::class)->name('contributions.my-statement');
        Route::post('contributions/generate', [ContributionController::class, 'generate'])->name('contributions.generate');
        Route::get('contributions/{contribution}', [ContributionController::class, 'show'])->name('contributions.show');
        Route::post('contributions/{contribution}/email-reminder', [ContributionEmailReminderController::class, 'send'])
            ->middleware(['subscription:'.PlatformPlanCatalog::EmailReminders, 'throttle:10,1'])
            ->name('contributions.email-reminder');
        Route::post('contributions/{contribution}/whatsapp-reminder', [ContributionWhatsAppReminderController::class, 'send'])
            ->middleware(['subscription:'.PlatformPlanCatalog::WhatsappReminders, 'throttle:10,1'])
            ->name('contributions.whatsapp-reminder');
        Route::post('contributions/{contribution}/web-push-reminder', [ContributionWebPushReminderController::class, 'send'])
            ->middleware(['subscription:'.PlatformPlanCatalog::WebPushReminders, 'throttle:10,1'])
            ->name('contributions.web-push-reminder');

        // Payments
        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::get('members/{member}/payments/create', [PaymentController::class, 'create'])->name('payments.create');
        Route::post('payments', [PaymentController::class, 'store'])->name('payments.store')
            ->middleware([HandlePrecognitiveRequests::class]);
        Route::post('payment-batches/{payment_batch}/reverse', PaymentBatchReversalController::class)
            ->name('payment-batches.reverse');
        Route::get('payment-batches/{payment_batch}/receipt', PaymentReceiptController::class)
            ->name('payment-batches.receipt');

        // Member Self-Pay (Paystack)
        Route::middleware('subscription:'.PlatformPlanCatalog::OnlinePayments)->group(function () {
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
        Route::post('expenses/{expense}/reverse', ExpenseReversalController::class)->name('expenses.reverse');

        // Fund Adjustments (Admin only for create/delete)
        Route::get('fund-adjustments', [FundAdjustmentController::class, 'index'])->name('fund-adjustments.index');
        Route::post('fund-adjustments', [FundAdjustmentController::class, 'store'])->name('fund-adjustments.store')
            ->middleware([HandlePrecognitiveRequests::class]);
        Route::post('fund-adjustments/{fund_adjustment}/reverse', FundAdjustmentReversalController::class)
            ->name('fund-adjustments.reverse');

        // Money-in and money-out reconciliation (family officers only)
        Route::prefix('reconciliation')->name('reconciliation.')->middleware('can:reconcile-family-funds')->group(function () {
            Route::get('/', [ReconciliationController::class, 'index'])->name('index');
            Route::post('imports', [ReconciliationImportController::class, 'store'])->name('imports.store');
            Route::post('imports/{reconciliation_import}/commit', [ReconciliationImportController::class, 'commit'])
                ->name('imports.commit');
            Route::post('transactions/{bank_transaction}/links', [ReconciliationLinkController::class, 'store'])
                ->name('links.store');
            Route::delete('links/{reconciliation_link}', [ReconciliationLinkController::class, 'destroy'])
                ->name('links.destroy');
            Route::patch('transactions/{bank_transaction}/status', [ReconciliationTransactionStatusController::class, 'update'])
                ->name('transactions.status');
            Route::post('periods', [ReconciliationPeriodController::class, 'store'])->name('periods.store');
            Route::post('periods/{reconciliation_period}/close', [ReconciliationPeriodController::class, 'close'])
                ->name('periods.close');
            Route::post('periods/{reconciliation_period}/reopen', [ReconciliationPeriodController::class, 'reopen'])
                ->name('periods.reopen');
            Route::post('settlements', [ProviderSettlementGroupController::class, 'store'])->name('settlements.store');
        });

        // Reports (Financial Secretary and Admin only)
        Route::prefix('reports')->name('reports.')->middleware(['can:generate-reports', 'subscription:'.PlatformPlanCatalog::Reports])->group(function () {
            Route::get('/', [ReportController::class, 'index'])->name('index');
            Route::get('monthly', [ReportController::class, 'monthly'])->name('monthly');
            Route::get('annual', [ReportController::class, 'annual'])->name('annual');
            Route::get('export', ReportExportController::class)->name('export');
            Route::get('artifacts/{reportArtifact}', [ReportArtifactController::class, 'show'])->name('artifacts.show');
            Route::post('schedules', [ReportScheduleController::class, 'store'])->name('schedules.store');
            Route::delete('schedules/{reportSchedule}', [ReportScheduleController::class, 'destroy'])->name('schedules.destroy');
        });

        // Family Settings (Admin only)
        Route::prefix('family')->name('family.')->group(function () {
            Route::get('settings', [FamilySettingsController::class, 'edit'])->name('settings');
            Route::put('settings', [FamilySettingsController::class, 'update'])->name('settings.update');
            Route::post('categories', [FamilySettingsController::class, 'storeCategory'])->name('categories.store');
            Route::put('categories/{category}', [FamilySettingsController::class, 'updateCategory'])->name('categories.update');
            Route::delete('categories/{category}', [FamilySettingsController::class, 'destroyCategory'])->name('categories.destroy');
            Route::get('banks', [FamilySettingsController::class, 'banks'])->name('banks');
            Route::get('archive', [FamilyArchiveController::class, 'show'])->name('archive.show');
            Route::post('archive', [FamilyArchiveController::class, 'store'])->name('archive.store');
            Route::post('archive/restore', [FamilyArchiveController::class, 'restore'])->name('archive.restore');
            Route::get('archive/export', [FamilyArchiveController::class, 'export'])->name('archive.export');

            Route::get('invitations', [InvitationController::class, 'index'])->name('invitations');
            Route::post('invitations', [InvitationController::class, 'store'])->name('invitations.store')->middleware('subscription');
            Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');
        });
    });
