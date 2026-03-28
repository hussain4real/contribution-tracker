<?php

use App\Models\Family;
use App\Models\PlatformPlan;
use App\Models\User;

function createSuperAdmin(): User
{
    $family = Family::factory()->create();

    return User::factory()->admin()->create([
        'family_id' => $family->id,
        'is_super_admin' => true,
    ]);
}

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
        ->assertInertia(fn ($page) => $page
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

    $plan = PlatformPlan::where('slug', 'pro')->first();
    expect($plan)->not->toBeNull()
        ->and($plan->name)->toBe('Pro')
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
