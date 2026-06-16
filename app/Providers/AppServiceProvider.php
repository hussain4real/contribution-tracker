<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Responses\LoginResponse;
use App\Http\Responses\PasskeyLoginResponse;
use App\Http\Responses\RedirectAsIntendedToCurrentFamily;
use App\Http\Responses\RegisterResponse;
use App\Http\Responses\TwoFactorLoginResponse;
use App\Http\Responses\VerifyEmailResponse;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
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
        // Gate for generating reports
        Gate::define('generate-reports', function (User $user) {
            return $user->activeRole()->canGenerateReports();
        });

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
}
