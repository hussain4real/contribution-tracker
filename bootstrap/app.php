<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureFamilyIsNotSuspended;
use App\Http\Middleware\EnsureFamilyMembership;
use App\Http\Middleware\EnsureFamilySubscription;
use App\Http\Middleware\EnsurePasswordIsNotTemporary;
use App\Http\Middleware\EnsureUserIsNotArchived;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetFamilyContext;
use App\Http\Middleware\SetFamilyUrlDefaults;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->validateCsrfTokens(except: [
            'webhooks/paystack',
            'webhooks/whatsapp',
        ]);

        $middleware->web(append: [
            EnsureUserIsNotArchived::class,
            EnsureFamilyIsNotSuspended::class,
            SetFamilyUrlDefaults::class,
            SetFamilyContext::class,
            EnsurePasswordIsNotTemporary::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'family.member' => EnsureFamilyMembership::class,
            'subscription' => EnsureFamilySubscription::class,
        ]);

        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: SetFamilyUrlDefaults::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response) {
            if ($response->getStatusCode() === 419) {
                return back()->with([
                    'message' => 'The page expired, please try again.',
                ]);
            }

            return $response;
        });
    })->create();
