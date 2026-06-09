<?php

declare(strict_types=1);

use App\Models\Family;
use App\Models\PlatformPlan;
use App\Models\User;
use App\Support\PlatformPlanCatalog;
use Database\Seeders\PlatformPlanSeeder;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;

function createSuperAdmin(): User
{
    $family = Family::factory()->create();

    return User::factory()->admin()->create([
        'family_id' => $family->id,
        'is_super_admin' => true,
    ]);
}

/**
 * @param  Collection<string, PlatformPlan>  $plans
 */
function seededPlatformPlan(Collection $plans, string $slug): PlatformPlan
{
    $plan = $plans->get($slug);

    if (! $plan instanceof PlatformPlan) {
        throw new RuntimeException("Expected seeded platform plan [{$slug}].");
    }

    return $plan;
}

it('seeds the freemium self serve pricing ladder and retires legacy plans', function () {
    foreach (['starter', 'pro', 'enterprise'] as $legacySlug) {
        PlatformPlan::create([
            'name' => ucfirst($legacySlug),
            'slug' => $legacySlug,
            'price' => 9999,
            'max_members' => 99,
            'features' => ['basic_contributions'],
            'is_active' => true,
            'sort_order' => 9,
            'paystack_plan_code' => 'PLN_'.$legacySlug,
        ]);
    }

    $this->seed(PlatformPlanSeeder::class);

    $activePlans = PlatformPlan::query()
        ->where('is_active', true)
        ->orderBy('sort_order')
        ->get()
        ->keyBy('slug');
    $freePlan = seededPlatformPlan($activePlans, PlatformPlanCatalog::Free);
    $familyPlan = seededPlatformPlan($activePlans, PlatformPlanCatalog::Family);
    $growthPlan = seededPlatformPlan($activePlans, PlatformPlanCatalog::Growth);
    $organizationPlan = seededPlatformPlan($activePlans, PlatformPlanCatalog::Organization);

    expect($activePlans->keys()->all())->toBe([
        PlatformPlanCatalog::Free,
        PlatformPlanCatalog::Family,
        PlatformPlanCatalog::Growth,
        PlatformPlanCatalog::Organization,
    ])
        ->and($freePlan->price)->toBe(0)
        ->and($freePlan->max_members)->toBe(5)
        ->and($freePlan->features)->toBe([
            PlatformPlanCatalog::BasicContributions,
            PlatformPlanCatalog::ManualPayments,
        ])
        ->and($familyPlan->price)->toBe(3000)
        ->and($familyPlan->max_members)->toBe(25)
        ->and($familyPlan->features)->toContain(
            PlatformPlanCatalog::OnlinePayments,
            PlatformPlanCatalog::Reports,
        )
        ->and($growthPlan->price)->toBe(7500)
        ->and($growthPlan->max_members)->toBe(75)
        ->and($growthPlan->features)->toContain(PlatformPlanCatalog::AiAssistant)
        ->and($organizationPlan->price)->toBe(20000)
        ->and($organizationPlan->max_members)->toBe(250)
        ->and($organizationPlan->features)->toContain(
            PlatformPlanCatalog::WhatsappMessaging,
            PlatformPlanCatalog::PrioritySupport,
        );

    $legacyActiveStates = PlatformPlan::query()
        ->whereIn('slug', ['starter', 'pro', 'enterprise'])
        ->get()
        ->mapWithKeys(fn (PlatformPlan $plan): array => [$plan->slug => $plan->is_active])
        ->sortKeys()
        ->all();

    expect($legacyActiveStates)
        ->toBe([
            'enterprise' => false,
            'pro' => false,
            'starter' => false,
        ]);
});

it('shows the plans page for super admin', function () {
    $admin = createSuperAdmin();

    PlatformPlan::create([
        'name' => 'Free', 'slug' => 'free', 'price' => 0,
        'max_members' => 5, 'features' => ['basic_contributions'],
        'is_active' => true, 'sort_order' => 0,
    ]);

    $this->actingAs($admin)
        ->get(route('platform.plans'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Platform/Plans')
            ->has('plans', 1)
            ->has('available_features')
        );
});

it('denies non-super-admin access to plans', function () {
    $family = Family::factory()->create();
    $user = User::factory()->create(['family_id' => $family->id]);

    $this->actingAs($user)
        ->get(route('platform.plans'))
        ->assertForbidden();
});

it('creates a new plan', function () {
    $admin = createSuperAdmin();

    $this->actingAs($admin)
        ->post(route('platform.plans.store'), [
            'name' => 'Pro',
            'slug' => 'pro',
            'price' => 5000,
            'max_members' => 20,
            'features' => ['basic_contributions', 'reports', 'exports'],
            'is_active' => true,
            'sort_order' => 1,
        ])
        ->assertRedirect();

    $plan = PlatformPlan::where('slug', 'pro')->firstOrFail();
    expect($plan->name)->toBe('Pro')
        ->and($plan->price)->toBe(5000)
        ->and($plan->max_members)->toBe(20)
        ->and($plan->features)->toBe(['basic_contributions', 'reports', 'exports']);
});

it('validates required fields when creating a plan', function () {
    $admin = createSuperAdmin();

    $this->actingAs($admin)
        ->post(route('platform.plans.store'), [])
        ->assertSessionHasErrors(['name', 'slug', 'price', 'sort_order']);
});

it('prevents duplicate slugs', function () {
    $admin = createSuperAdmin();

    PlatformPlan::create([
        'name' => 'Free', 'slug' => 'free', 'price' => 0,
        'max_members' => 5, 'features' => [], 'is_active' => true, 'sort_order' => 0,
    ]);

    $this->actingAs($admin)
        ->post(route('platform.plans.store'), [
            'name' => 'Free Again',
            'slug' => 'free',
            'price' => 0,
            'sort_order' => 1,
        ])
        ->assertSessionHasErrors('slug');
});

it('updates an existing plan', function () {
    $admin = createSuperAdmin();

    $plan = PlatformPlan::create([
        'name' => 'Basic', 'slug' => 'basic', 'price' => 1000,
        'max_members' => 10, 'features' => ['basic_contributions'],
        'is_active' => true, 'sort_order' => 0,
    ]);

    $this->actingAs($admin)
        ->put(route('platform.plans.update', $plan), [
            'name' => 'Basic Plus',
            'slug' => 'basic',
            'price' => 2000,
            'max_members' => 15,
            'features' => ['basic_contributions', 'reports'],
            'is_active' => true,
            'sort_order' => 0,
        ])
        ->assertRedirect();

    $plan->refresh();
    expect($plan->name)->toBe('Basic Plus')
        ->and($plan->price)->toBe(2000)
        ->and($plan->max_members)->toBe(15)
        ->and($plan->features)->toBe(['basic_contributions', 'reports']);
});

it('preserves truthy active values accepted by validation', function (bool|int|string $activeValue) {
    $admin = createSuperAdmin();
    $slug = match (true) {
        $activeValue === true => 'active-plan-true',
        $activeValue === 1 => 'active-plan-integer-one',
        default => 'active-plan-string-one',
    };

    $this->actingAs($admin)
        ->post(route('platform.plans.store'), [
            'name' => 'Active Plan',
            'slug' => $slug,
            'price' => 1000,
            'max_members' => 10,
            'features' => [],
            'is_active' => $activeValue,
            'sort_order' => 2,
        ])
        ->assertRedirect();

    $plan = PlatformPlan::where('name', 'Active Plan')->firstOrFail();

    expect($plan->is_active)->toBeTrue();

    $plan->update(['is_active' => false]);

    $this->actingAs($admin)
        ->put(route('platform.plans.update', $plan), [
            'name' => 'Still Active Plan',
            'slug' => $plan->slug,
            'price' => 2000,
            'max_members' => 15,
            'features' => ['reports'],
            'is_active' => $activeValue,
            'sort_order' => 3,
        ])
        ->assertRedirect();

    expect($plan->refresh()->is_active)->toBeTrue();
})->with([
    'boolean true' => true,
    'integer one' => 1,
    'string one' => '1',
]);

it('toggles plan active status', function () {
    $admin = createSuperAdmin();

    $plan = PlatformPlan::create([
        'name' => 'Pro', 'slug' => 'pro', 'price' => 5000,
        'max_members' => 20, 'features' => [], 'is_active' => true, 'sort_order' => 1,
    ]);

    $this->actingAs($admin)
        ->post(route('platform.plans.toggle-active', $plan))
        ->assertRedirect();

    expect($plan->refresh()->is_active)->toBeFalse();

    $this->actingAs($admin)
        ->post(route('platform.plans.toggle-active', $plan))
        ->assertRedirect();

    expect($plan->refresh()->is_active)->toBeTrue();
});

it('deletes a plan with no families', function () {
    $admin = createSuperAdmin();

    $plan = PlatformPlan::create([
        'name' => 'Test', 'slug' => 'test', 'price' => 0,
        'max_members' => 5, 'features' => [], 'is_active' => false, 'sort_order' => 99,
    ]);

    $this->actingAs($admin)
        ->delete(route('platform.plans.destroy', $plan))
        ->assertRedirect();

    expect(PlatformPlan::find($plan->id))->toBeNull();
});

it('prevents deleting a plan with assigned families', function () {
    $admin = createSuperAdmin();

    $plan = PlatformPlan::create([
        'name' => 'Popular', 'slug' => 'popular', 'price' => 3000,
        'max_members' => 10, 'features' => [], 'is_active' => true, 'sort_order' => 1,
    ]);

    // Assign a family to this plan
    Family::factory()->create(['platform_plan_id' => $plan->id]);

    $this->actingAs($admin)
        ->delete(route('platform.plans.destroy', $plan))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(PlatformPlan::find($plan->id))->not->toBeNull();
});
