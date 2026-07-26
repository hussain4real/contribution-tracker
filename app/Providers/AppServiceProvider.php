<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Responses\LoginResponse;
use App\Http\Responses\PasskeyLoginResponse;
use App\Http\Responses\RedirectAsIntendedToCurrentFamily;
use App\Http\Responses\RegisterResponse;
use App\Http\Responses\TwoFactorLoginResponse;
use App\Http\Responses\VerifyEmailResponse;
use App\Models\Expense;
use App\Models\FamilyMembership;
use App\Models\FamilyMembershipCategoryAssignment;
use App\Models\FinancialReversal;
use App\Models\FundAdjustment;
use App\Models\PaymentBatch;
use App\Models\PaystackTransaction;
use App\Models\ProviderSettlementGroup;
use App\Models\User;
use App\Observers\ExpenseObserver;
use App\Observers\FamilyMembershipCategoryAssignmentObserver;
use App\Observers\FamilyMembershipObserver;
use App\Observers\FinancialReversalObserver;
use App\Observers\FundAdjustmentObserver;
use App\Observers\PaymentBatchObserver;
use App\Observers\PaystackTransactionObserver;
use App\Policies\ReconciliationPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Inertia\ExceptionResponse;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;
use Laravel\Fortify\Http\Responses\RedirectAsIntended;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;
use Laravel\Passport\Passport;
use Laravel\Pennant\Middleware\EnsureFeaturesAreActive;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
        $this->app->singleton(TwoFactorLoginResponseContract::class, TwoFactorLoginResponse::class);
        $this->app->singleton(PasskeyLoginResponseContract::class, PasskeyLoginResponse::class);
        $this->app->singleton(VerifyEmailResponseContract::class, VerifyEmailResponse::class);
        $this->app->bind(
            RedirectAsIntended::class,
            fn ($app, array $parameters): RedirectAsIntendedToCurrentFamily => new RedirectAsIntendedToCurrentFamily(
                is_string($parameters['name'] ?? null) ? $parameters['name'] : 'default',
            ),
        );
    }

    public function boot(): void
    {
        $this->requireEncryptedProductionBackups();

        Relation::morphMap([
            'expense' => Expense::class,
            'family_membership' => FamilyMembership::class,
            'family_category_assignment' => FamilyMembershipCategoryAssignment::class,
            'financial_reversal' => FinancialReversal::class,
            'fund_adjustment' => FundAdjustment::class,
            'payment_batch' => PaymentBatch::class,
            'paystack_transaction' => PaystackTransaction::class,
            'provider_settlement_group' => ProviderSettlementGroup::class,
        ]);

        FamilyMembership::observe(FamilyMembershipObserver::class);
        FamilyMembershipCategoryAssignment::observe(FamilyMembershipCategoryAssignmentObserver::class);
        PaymentBatch::observe(PaymentBatchObserver::class);
        Expense::observe(ExpenseObserver::class);
        FundAdjustment::observe(FundAdjustmentObserver::class);
        FinancialReversal::observe(FinancialReversalObserver::class);
        PaystackTransaction::observe(PaystackTransactionObserver::class);

        // Gate for generating reports
        Gate::define('generate-reports', function (User $user) {
            return $user->activeRole()->canGenerateReports();
        });
        Gate::define('reconcile-family-funds', [ReconciliationPolicy::class, 'manage']);
        Gate::define('reopen-reconciliation', [ReconciliationPolicy::class, 'reopen']);

        RateLimiter::for('whatsapp-notifications', function (object $job): Limit {
            $rateLimit = config('services.whatsapp.rate_limit_per_minute', 60);
            $rateLimit = is_numeric($rateLimit) ? (int) $rateLimit : 60;

            return Limit::perMinute(max(1, $rateLimit))
                ->by('whatsapp-notifications');
        });

        Passport::authorizationView(function (array $parameters) {
            return response()->view('mcp.authorize', $parameters);
        });

        Inertia::handleExceptionsUsing(function (ExceptionResponse $response) {
            if ($response->request->is('mcp/*', '.well-known/oauth-*', 'oauth/*')) {
                return null;
            }

            if (in_array($response->statusCode(), [403, 404, 500, 503])) {
                return $response->render('ErrorPage', [
                    'status' => $response->statusCode(),
                ])->withSharedData();
            }
        });

        Model::automaticallyEagerLoadRelationships();

        EnsureFeaturesAreActive::whenInactive(function ($request, array $features) {
            return redirect()->route('dashboard')
                ->with('warning', 'This feature is not currently available for your account.');
        });

        Model::preventLazyLoading(! $this->app->isProduction());

        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation): void {
            $class = $model::class;
            $message = "Attempted to lazy load [{$relation}] on model [{$class}].";

            if ($this->app->isProduction()) {
                info($message);
            } else {
                throw new LazyLoadingViolationException($model, $relation);
            }
        });
    }

    private function requireEncryptedProductionBackups(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            if (! $this->app->isProduction()) {
                return;
            }

            if (! in_array($event->command, ['backup:run', 'backups:sync-google-drive'], true)) {
                return;
            }

            $password = config('backup.backup.password');

            if (is_string($password) && trim($password) !== '') {
                return;
            }

            throw new RuntimeException('BACKUP_ARCHIVE_PASSWORD is required before production backups can run.');
        });
    }
}
