<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Inertia\ExceptionResponse;
use Inertia\Inertia;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Gate for generating reports
        Gate::define('generate-reports', function (User $user) {
            return $user->role->canGenerateReports();
        });

        Inertia::handleExceptionsUsing(function (ExceptionResponse $response) {
            if (in_array($response->statusCode(), [403, 404, 500, 503])) {
                return $response->render('ErrorPage', [
                    'status' => $response->statusCode(),
                ])->withSharedData();
            }
        });

        Model::automaticallyEagerLoadRelationships();
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
