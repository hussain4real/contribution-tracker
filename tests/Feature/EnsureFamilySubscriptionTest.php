<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureFamilySubscription;
use App\Models\Family;
use App\Models\PlatformPlan;
use App\Models\User;
use App\Support\PlatformPlanCatalog;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

function makeMiddlewareRequest(User $user, string $routeName = 'dashboard', ?string $feature = null): Response
{
    $middleware = new EnsureFamilySubscription;

    $request = Request::create(route($routeName), 'GET');
    $request->setUserResolver(fn () => $user);
    $request->setRouteResolver(fn () => app('router')->getRoutes()->getByName($routeName));

    return $middleware->handle($request, fn ($r) => response('OK'), $feature);
}

function makeMiddlewareJsonRequest(User $user, string $routeName = 'dashboard', ?string $feature = null): Response
{
    $middleware = new EnsureFamilySubscription;

    $request = Request::create(route($routeName), 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
    $request->setUserResolver(fn () => $user);
    $request->setRouteResolver(fn () => app('router')->getRoutes()->getByName($routeName));

    return $middleware->handle($request, fn ($r) => response('OK'), $feature);
}

/**
 * @param  array<int|string, mixed>  $features
 */
function createMiddlewarePlan(
    string $name,
    string $slug,
    int $price,
    ?int $maxMembers,
    array $features,
    int $sortOrder,
): PlatformPlan {
    return PlatformPlan::create([
        'name' => $name,
        'slug' => $slug,
        'price' => $price,
        'max_members' => $maxMembers,
        'features' => $features,
        'is_active' => true,
        'sort_order' => $sortOrder,
    ]);
}

it('allows users without a family', function () {
    $user = User::factory()->create(['family_id' => null]);

    $response = makeMiddlewareRequest($user);

    expect(responseContent($response))->toBe('OK');
});

it('allows users on free plan (no plan assigned)', function () {
    $user = User::factory()->create();

    $response = makeMiddlewareRequest($user);

    expect(responseContent($response))->toBe('OK');
});

it('uses the seeded free plan for families without an assigned plan', function () {
    createMiddlewarePlan(
        'Free',
        PlatformPlanCatalog::Free,
        0,
        1,
        [PlatformPlanCatalog::BasicContributions, PlatformPlanCatalog::ManualPayments],
        0,
    );

    $family = Family::factory()->create(['platform_plan_id' => null]);
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);

    $response = makeMiddlewareJsonRequest($admin, 'family.invitations.store');

    expect($response->getStatusCode())->toBe(403)
        ->and(decodeJsonObject(responseContent($response)))->toBe([
            'message' => 'Your plan allows up to 1 members. Please upgrade to add more.',
        ]);
});

it('blocks paid features for families without an assigned plan when free exists', function () {
    createMiddlewarePlan(
        'Free',
        PlatformPlanCatalog::Free,
        0,
        5,
        [PlatformPlanCatalog::BasicContributions, PlatformPlanCatalog::ManualPayments],
        0,
    );

    $family = Family::factory()->create(['platform_plan_id' => null]);
    $user = User::factory()->create(['family_id' => $family->id]);

    $response = makeMiddlewareJsonRequest($user, 'dashboard', PlatformPlanCatalog::OnlinePayments);

    expect($response->getStatusCode())->toBe(403)
        ->and(decodeJsonObject(responseContent($response)))->toBe([
            'message' => 'This feature is not available on your current plan. Please upgrade.',
        ]);
});

it('enforces the freemium feature ladder', function (
    string $planName,
    string $slug,
    int $price,
    int $maxMembers,
    array $features,
    string $requestedFeature,
    bool $allowed,
) {
    $plan = createMiddlewarePlan($planName, $slug, $price, $maxMembers, $features, 1);

    $family = Family::factory()->create([
        'platform_plan_id' => $plan->id,
        'subscription_status' => $plan->isPaid() ? 'active' : 'free',
    ]);
    $user = User::factory()->create(['family_id' => $family->id]);

    $response = makeMiddlewareRequest($user, 'dashboard', $requestedFeature);

    expect($response->getStatusCode())->toBe($allowed ? 200 : 302);
})->with([
    'free blocks online payments' => [
        'Free',
        PlatformPlanCatalog::Free,
        0,
        5,
        [PlatformPlanCatalog::BasicContributions, PlatformPlanCatalog::ManualPayments],
        PlatformPlanCatalog::OnlinePayments,
        false,
    ],
    'free blocks reports' => [
        'Free',
        PlatformPlanCatalog::Free,
        0,
        5,
        [PlatformPlanCatalog::BasicContributions, PlatformPlanCatalog::ManualPayments],
        PlatformPlanCatalog::Reports,
        false,
    ],
    'free blocks ai' => [
        'Free',
        PlatformPlanCatalog::Free,
        0,
        5,
        [PlatformPlanCatalog::BasicContributions, PlatformPlanCatalog::ManualPayments],
        PlatformPlanCatalog::AiAssistant,
        false,
    ],
    'free blocks whatsapp' => [
        'Free',
        PlatformPlanCatalog::Free,
        0,
        5,
        [PlatformPlanCatalog::BasicContributions, PlatformPlanCatalog::ManualPayments],
        PlatformPlanCatalog::WhatsappMessaging,
        false,
    ],
    'family allows online payments' => [
        'Family',
        PlatformPlanCatalog::Family,
        3000,
        25,
        [
            PlatformPlanCatalog::BasicContributions,
            PlatformPlanCatalog::ManualPayments,
            PlatformPlanCatalog::OnlinePayments,
            PlatformPlanCatalog::Reports,
        ],
        PlatformPlanCatalog::OnlinePayments,
        true,
    ],
    'family allows reports' => [
        'Family',
        PlatformPlanCatalog::Family,
        3000,
        25,
        [
            PlatformPlanCatalog::BasicContributions,
            PlatformPlanCatalog::ManualPayments,
            PlatformPlanCatalog::OnlinePayments,
            PlatformPlanCatalog::Reports,
        ],
        PlatformPlanCatalog::Reports,
        true,
    ],
    'family blocks ai' => [
        'Family',
        PlatformPlanCatalog::Family,
        3000,
        25,
        [
            PlatformPlanCatalog::BasicContributions,
            PlatformPlanCatalog::ManualPayments,
            PlatformPlanCatalog::OnlinePayments,
            PlatformPlanCatalog::Reports,
        ],
        PlatformPlanCatalog::AiAssistant,
        false,
    ],
    'growth allows ai' => [
        'Growth',
        PlatformPlanCatalog::Growth,
        7500,
        75,
        [
            PlatformPlanCatalog::BasicContributions,
            PlatformPlanCatalog::ManualPayments,
            PlatformPlanCatalog::OnlinePayments,
            PlatformPlanCatalog::Reports,
            PlatformPlanCatalog::Exports,
            PlatformPlanCatalog::AiAssistant,
        ],
        PlatformPlanCatalog::AiAssistant,
        true,
    ],
    'growth blocks whatsapp' => [
        'Growth',
        PlatformPlanCatalog::Growth,
        7500,
        75,
        [
            PlatformPlanCatalog::BasicContributions,
            PlatformPlanCatalog::ManualPayments,
            PlatformPlanCatalog::OnlinePayments,
            PlatformPlanCatalog::Reports,
            PlatformPlanCatalog::Exports,
            PlatformPlanCatalog::AiAssistant,
        ],
        PlatformPlanCatalog::WhatsappMessaging,
        false,
    ],
    'organization allows whatsapp' => [
        'Organization',
        PlatformPlanCatalog::Organization,
        20000,
        250,
        [
            PlatformPlanCatalog::BasicContributions,
            PlatformPlanCatalog::ManualPayments,
            PlatformPlanCatalog::OnlinePayments,
            PlatformPlanCatalog::Reports,
            PlatformPlanCatalog::Exports,
            PlatformPlanCatalog::AiAssistant,
            PlatformPlanCatalog::WhatsappMessaging,
            PlatformPlanCatalog::PrioritySupport,
        ],
        PlatformPlanCatalog::WhatsappMessaging,
        true,
    ],
]);

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

    expect(responseContent($response))->toBe('OK');
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

it('returns json when plan does not include a requested feature', function () {
    $plan = PlatformPlan::create([
        'name' => 'Free',
        'slug' => 'free',
        'price' => 0,
        'max_members' => 5,
        'features' => ['basic_contributions'],
        'is_active' => true,
        'sort_order' => 0,
    ]);
    $family = Family::factory()->create(['platform_plan_id' => $plan->id]);
    $user = User::factory()->create(['family_id' => $family->id]);

    $response = makeMiddlewareJsonRequest($user, 'dashboard', 'online_payments');

    expect($response->getStatusCode())->toBe(403)
        ->and(decodeJsonObject(responseContent($response)))->toBe([
            'message' => 'This feature is not available on your current plan. Please upgrade.',
        ]);
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

    expect(responseContent($response))->toBe('OK');
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

it('returns json for inactive paid subscriptions', function () {
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
        'subscription_status' => 'past_due',
    ]);
    $user = User::factory()->create(['family_id' => $family->id]);

    $response = makeMiddlewareJsonRequest($user, 'contributions.index');

    expect($response->getStatusCode())->toBe(403)
        ->and(decodeJsonObject(responseContent($response)))->toBe([
            'message' => 'Your subscription is inactive. Please update your subscription.',
        ]);
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

    expect(responseContent($response))->toBe('OK');
});

it('blocks adding members when at the plan limit', function () {
    $plan = PlatformPlan::create([
        'name' => 'Free',
        'slug' => 'free',
        'price' => 0,
        'max_members' => 2,
        'features' => ['basic_contributions', 'manual_payments'],
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $family = Family::factory()->create(['platform_plan_id' => $plan->id]);
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);
    User::factory()->create(['family_id' => $family->id]);

    // 2 members now — at the limit
    $this->actingAs($admin)
        ->post(route('members.store'), [
            'name' => 'New Member',
            'email' => 'new@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'member',
            'category' => 'employed',
        ])
        ->assertRedirect(route('subscription.index'));
});

it('returns json when adding members would exceed the plan limit', function () {
    $plan = PlatformPlan::create([
        'name' => 'Free',
        'slug' => 'free',
        'price' => 0,
        'max_members' => 1,
        'features' => ['basic_contributions', 'manual_payments'],
        'is_active' => true,
        'sort_order' => 0,
    ]);
    $family = Family::factory()->create(['platform_plan_id' => $plan->id]);
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);

    $response = makeMiddlewareJsonRequest($admin, 'family.invitations.store');

    expect($response->getStatusCode())->toBe(403)
        ->and(decodeJsonObject(responseContent($response)))->toBe([
            'message' => 'Your plan allows up to 1 members. Please upgrade to add more.',
        ]);
});

it('allows adding members when under the plan limit', function () {
    $plan = PlatformPlan::create([
        'name' => 'Free',
        'slug' => 'free',
        'price' => 0,
        'max_members' => 5,
        'features' => ['basic_contributions', 'manual_payments'],
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $family = Family::factory()->create(['platform_plan_id' => $plan->id]);
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);

    // 1 member — well under the limit
    $this->actingAs($admin)
        ->post(route('members.store'), [
            'name' => 'New Member',
            'email' => 'new@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'member',
            'category' => 'employed',
        ])
        ->assertRedirect(route('members.index'));

    expect(User::where('email', 'new@test.com')->exists())->toBeTrue();
});

it('blocks access to create member page when at the plan limit', function () {
    $plan = PlatformPlan::create([
        'name' => 'Free',
        'slug' => 'free',
        'price' => 0,
        'max_members' => 1,
        'features' => ['basic_contributions', 'manual_payments'],
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $family = Family::factory()->create(['platform_plan_id' => $plan->id]);
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);

    // 1 member — at the limit
    $this->actingAs($admin)
        ->get(route('members.create'))
        ->assertRedirect(route('subscription.index'));
});
