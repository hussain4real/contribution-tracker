<?php

use App\Http\Middleware\EnsureFamilySubscription;
use App\Models\Family;
use App\Models\PlatformPlan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

function makeMiddlewareRequest(User $user, string $routeName = 'dashboard', ?string $feature = null): TestResponse|Response
{
    $middleware = new EnsureFamilySubscription;

    $request = Request::create(route($routeName), 'GET');
    $request->setUserResolver(fn () => $user);
    $request->setRouteResolver(fn () => app('router')->getRoutes()->getByName($routeName));

    return $middleware->handle($request, fn ($r) => response('OK'), $feature);
}

it('allows users without a family', function () {
    $user = User::factory()->create(['family_id' => null]);

    $response = makeMiddlewareRequest($user);

    expect($response->getContent())->toBe('OK');
});

it('allows users on free plan (no plan assigned)', function () {
    $user = User::factory()->create();

    $response = makeMiddlewareRequest($user);

    expect($response->getContent())->toBe('OK');
});

it('allows users when plan has unlimited members', function () {
    $plan = PlatformPlan::create([
        'name' => 'Enterprise',
        'slug' => 'enterprise',
        'price' => 10000,
        'max_members' => null,
        'features' => ['basic_contributions', 'online_payments'],
        'is_active' => true,
        'sort_order' => 3,
    ]);

    $family = Family::factory()->create([
        'platform_plan_id' => $plan->id,
        'subscription_status' => 'active',
    ]);
    $user = User::factory()->create(['family_id' => $family->id]);

    $response = makeMiddlewareRequest($user);

    expect($response->getContent())->toBe('OK');
});

it('blocks feature access when plan does not include the feature', function () {
    $plan = PlatformPlan::create([
        'name' => 'Free',
        'slug' => 'free',
        'price' => 0,
        'max_members' => 5,
        'features' => ['basic_contributions'],
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $family = Family::factory()->create([
        'platform_plan_id' => $plan->id,
    ]);
    $user = User::factory()->create(['family_id' => $family->id]);

    $response = makeMiddlewareRequest($user, 'dashboard', 'online_payments');

    expect($response->getStatusCode())->toBe(302);
});

it('allows feature access when plan includes the feature', function () {
    $plan = PlatformPlan::create([
        'name' => 'Starter',
        'slug' => 'starter',
        'price' => 2000,
        'max_members' => 20,
        'features' => ['basic_contributions', 'online_payments'],
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $family = Family::factory()->create([
        'platform_plan_id' => $plan->id,
        'subscription_status' => 'active',
    ]);
    $user = User::factory()->create(['family_id' => $family->id]);

    $response = makeMiddlewareRequest($user, 'dashboard', 'online_payments');

    expect($response->getContent())->toBe('OK');
});

it('redirects to subscription page for cancelled paid plan', function () {
    $plan = PlatformPlan::create([
        'name' => 'Starter',
        'slug' => 'starter',
        'price' => 2000,
        'max_members' => 20,
        'features' => ['basic_contributions'],
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $family = Family::factory()->create([
        'platform_plan_id' => $plan->id,
        'subscription_status' => 'cancelled',
    ]);
    $user = User::factory()->create(['family_id' => $family->id]);

    // Non-exempt route should redirect
    $response = makeMiddlewareRequest($user, 'contributions.index');

    expect($response->getStatusCode())->toBe(302);
});

it('allows dashboard access for cancelled paid plan', function () {
    $plan = PlatformPlan::create([
        'name' => 'Starter',
        'slug' => 'starter',
        'price' => 2000,
        'max_members' => 20,
        'features' => ['basic_contributions'],
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $family = Family::factory()->create([
        'platform_plan_id' => $plan->id,
        'subscription_status' => 'cancelled',
    ]);
    $user = User::factory()->create(['family_id' => $family->id]);

    $response = makeMiddlewareRequest($user, 'dashboard');

    expect($response->getContent())->toBe('OK');
});
