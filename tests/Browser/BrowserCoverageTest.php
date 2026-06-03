<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;

/**
 * @return list<string>
 */
function browserPageRouteNames(): array
{
    return [
        'ai.index',
        'appearance.edit',
        'changelog',
        'contributions.index',
        'contributions.my',
        'contributions.show',
        'dashboard',
        'data-deletion',
        'expenses.create',
        'expenses.index',
        'family.invitations',
        'family.settings',
        'fund-adjustments.index',
        'home',
        'inbox.whatsapp.index',
        'inbox.whatsapp.show',
        'invitations.accept',
        'login',
        'members.create',
        'members.edit',
        'members.index',
        'members.show',
        'notifications.index',
        'passkeys.show',
        'password.confirm',
        'password.request',
        'password.reset',
        'pay.index',
        'payments.create',
        'payments.index',
        'platform.dashboard',
        'platform.families',
        'platform.families.show',
        'platform.feature-flags',
        'platform.plans',
        'platform.users',
        'privacy',
        'profile.edit',
        'register',
        'reports.annual',
        'reports.index',
        'reports.monthly',
        'subscription.index',
        'terms',
        'two-factor.login',
        'two-factor.show',
        'user-password.edit',
        'verification.notice',
    ];
}

/**
 * @return list<string>
 */
function browserCoveredRouteNames(): array
{
    return array_keys(browserCoverageAssignments());
}

/**
 * @return array<string, list<string>>
 */
function browserCoverageAssignments(): array
{
    return [
        'ai.index' => ['smoke:authenticated-family', 'workflow:mobile-ai-entrypoint'],
        'appearance.edit' => ['smoke:authenticated-family'],
        'changelog' => ['smoke:authenticated-family'],
        'contributions.index' => ['smoke:authenticated-family'],
        'contributions.my' => ['smoke:authenticated-family', 'workflow:member-history'],
        'contributions.show' => ['smoke:authenticated-family', 'workflow:member-history'],
        'dashboard' => ['smoke:authenticated-family', 'workflow:dashboard'],
        'data-deletion' => ['smoke:guest'],
        'expenses.create' => ['smoke:authenticated-family', 'workflow:financial-operations'],
        'expenses.index' => ['smoke:authenticated-family', 'workflow:financial-operations'],
        'family.invitations' => ['smoke:authenticated-family', 'workflow:family-admin'],
        'family.settings' => ['smoke:authenticated-family', 'workflow:family-admin'],
        'fund-adjustments.index' => ['smoke:authenticated-family', 'workflow:financial-operations'],
        'home' => ['smoke:guest'],
        'inbox.whatsapp.index' => ['smoke:authenticated-family'],
        'inbox.whatsapp.show' => ['smoke:authenticated-family'],
        'invitations.accept' => ['smoke:guest'],
        'login' => ['smoke:guest', 'workflow:authentication'],
        'members.create' => ['smoke:authenticated-family', 'workflow:member-management'],
        'members.edit' => ['smoke:authenticated-family', 'workflow:member-management'],
        'members.index' => ['smoke:authenticated-family', 'workflow:member-management'],
        'members.show' => ['smoke:authenticated-family', 'workflow:member-management'],
        'notifications.index' => ['smoke:authenticated-family', 'workflow:mobile-shell'],
        'passkeys.show' => ['smoke:authenticated-family'],
        'password.confirm' => ['smoke:authenticated-family'],
        'password.request' => ['smoke:guest'],
        'password.reset' => ['smoke:guest'],
        'pay.index' => ['smoke:authenticated-family'],
        'payments.create' => ['smoke:authenticated-family', 'workflow:payment-recording'],
        'payments.index' => ['smoke:authenticated-family', 'workflow:payment-recording'],
        'platform.dashboard' => ['smoke:platform-admin', 'workflow:platform-navigation'],
        'platform.families' => ['smoke:platform-admin', 'workflow:platform-navigation'],
        'platform.families.show' => ['smoke:platform-admin', 'workflow:platform-navigation'],
        'platform.feature-flags' => ['smoke:platform-admin', 'workflow:platform-navigation'],
        'platform.plans' => ['smoke:platform-admin', 'workflow:platform-navigation'],
        'platform.users' => ['smoke:platform-admin', 'workflow:platform-navigation'],
        'privacy' => ['smoke:guest'],
        'profile.edit' => ['smoke:authenticated-family'],
        'register' => ['smoke:guest'],
        'reports.annual' => ['smoke:authenticated-family'],
        'reports.index' => ['smoke:authenticated-family'],
        'reports.monthly' => ['smoke:authenticated-family'],
        'subscription.index' => ['smoke:authenticated-family'],
        'terms' => ['smoke:guest'],
        'two-factor.login' => ['smoke:authentication-challenge'],
        'two-factor.show' => ['smoke:authenticated-family'],
        'user-password.edit' => ['smoke:authenticated-family'],
        'verification.notice' => ['smoke:authentication-challenge'],
    ];
}

function browserCoverageExcludedRouteName(string $routeName): bool
{
    if (Str::startsWith($routeName, [
        'debugbar.',
        'mcp.',
        'passport.',
        'storage.',
    ])) {
        return true;
    }

    return in_array($routeName, [
        'family.banks',
        'passkey.confirm-options',
        'passkey.login-options',
        'passkey.registration-options',
        'passkey.two-factor.options',
        'password.confirmation',
        'pay.callback',
        'platform.families.export',
        'platform.users.export',
        'subscription.callback',
        'two-factor.qr-code',
        'two-factor.recovery-codes',
        'two-factor.secret-key',
        'verification.verify',
        'webhooks.whatsapp.verify',
    ], true);
}

it('classifies every browser reachable GET page route', function () {
    $getRouteNames = collect(RouteFacade::getRoutes()->getRoutes())
        ->filter(fn (Route $route): bool => in_array('GET', $route->methods(), true))
        ->map(fn (Route $route): ?string => $route->getName())
        ->filter(fn (?string $routeName): bool => is_string($routeName) && $routeName !== '')
        ->values();

    $classifiedRouteNames = collect([
        ...browserPageRouteNames(),
        ...$getRouteNames
            ->filter(fn (string $routeName): bool => browserCoverageExcludedRouteName($routeName))
            ->all(),
    ]);

    $missingRouteNames = array_values(array_diff($getRouteNames->all(), $classifiedRouteNames->all()));

    expect($missingRouteNames)->toBe([]);
});

it('has browser coverage assigned for every browser page route', function () {
    expect(browserCoveredRouteNames())->toEqualCanonicalizing(browserPageRouteNames());

    expect(collect(browserCoverageAssignments())
        ->filter(fn (array $assignments): bool => $assignments === [])
        ->keys()
        ->all())->toBe([]);
});
