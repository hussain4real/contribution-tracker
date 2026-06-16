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
        'password.confirm',
        'password.request',
        'password.reset',
        'pay.index',
        'payments.create',
        'payments.index',
        'filament.platform.auth.login',
        'filament.platform.pages.dashboard',
        'filament.platform.pages.feature-flags',
        'filament.platform.resources.families.index',
        'filament.platform.resources.families.view',
        'filament.platform.resources.plans.create',
        'filament.platform.resources.plans.edit',
        'filament.platform.resources.plans.index',
        'filament.platform.resources.users.index',
        'filament.platform.resources.users.view',
        'pricing',
        'privacy',
        'profile.edit',
        'register',
        'reports.annual',
        'reports.index',
        'reports.monthly',
        'security.edit',
        'subscription.index',
        'terms',
        'two-factor.login',
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
        'password.confirm' => ['smoke:authenticated-family'],
        'password.request' => ['smoke:guest'],
        'password.reset' => ['smoke:guest'],
        'pay.index' => ['smoke:authenticated-family'],
        'payments.create' => ['smoke:authenticated-family', 'workflow:payment-recording'],
        'payments.index' => ['smoke:authenticated-family', 'workflow:payment-recording'],
        'filament.platform.auth.login' => ['smoke:guest', 'workflow:authentication'],
        'filament.platform.pages.dashboard' => ['smoke:platform-admin', 'workflow:platform-navigation'],
        'filament.platform.pages.feature-flags' => ['smoke:platform-admin', 'workflow:platform-navigation'],
        'filament.platform.resources.families.index' => ['smoke:platform-admin', 'workflow:platform-navigation'],
        'filament.platform.resources.families.view' => ['smoke:platform-admin', 'workflow:platform-navigation'],
        'filament.platform.resources.plans.create' => ['smoke:platform-admin', 'workflow:platform-navigation'],
        'filament.platform.resources.plans.edit' => ['smoke:platform-admin', 'workflow:platform-navigation'],
        'filament.platform.resources.plans.index' => ['smoke:platform-admin', 'workflow:platform-navigation'],
        'filament.platform.resources.users.index' => ['smoke:platform-admin', 'workflow:platform-navigation'],
        'filament.platform.resources.users.view' => ['smoke:platform-admin', 'workflow:platform-navigation'],
        'pricing' => ['smoke:guest'],
        'privacy' => ['smoke:guest'],
        'profile.edit' => ['smoke:authenticated-family'],
        'register' => ['smoke:guest'],
        'reports.annual' => ['smoke:authenticated-family'],
        'reports.index' => ['smoke:authenticated-family'],
        'reports.monthly' => ['smoke:authenticated-family'],
        'security.edit' => ['smoke:authenticated-family'],
        'subscription.index' => ['smoke:authenticated-family'],
        'terms' => ['smoke:guest'],
        'two-factor.login' => ['smoke:authentication-challenge'],
        'verification.notice' => ['smoke:authentication-challenge'],
    ];
}

function browserCoverageExcludedRouteName(string $routeName): bool
{
    if (Str::startsWith($routeName, [
        'debugbar.',
        'filament.exports.',
        'filament.imports.',
        'livewire.',
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
        'password.confirmation',
        'pay.callback',
        'platform.families.export',
        'platform.users.export',
        'subscription.callback',
        'two-factor.qr-code',
        'two-factor.recovery-codes',
        'two-factor.secret-key',
        'verification.verify',
        'well-known.passkeys',
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
