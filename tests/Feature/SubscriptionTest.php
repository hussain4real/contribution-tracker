<?php

use App\Models\Family;
use App\Models\PlatformPlan;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.paystack.secret_key' => 'sk_test_secret',
        'services.paystack.public_key' => 'pk_test_public',
        'services.paystack.base_url' => 'https://api.paystack.co',
    ]);
});

it('shows subscription page for authenticated user', function () {
    PlatformPlan::create([
        'name' => 'Free',
        'slug' => 'free',
        'price' => 0,
        'max_members' => 5,
        'features' => ['basic_contributions'],
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('subscription.index'))
        ->assertSuccessful();
});

it('redirects guests from subscription page', function () {
    $this->get(route('subscription.index'))
        ->assertRedirect();
});

it('allows admin to subscribe to a paid plan', function () {
    Http::fake([
        'api.paystack.co/plan' => Http::response([
            'status' => true,
            'data' => ['plan_code' => 'PLN_test123'],
        ]),
        'api.paystack.co/customer' => Http::response([
            'status' => true,
            'data' => ['customer_code' => 'CUS_test123'],
        ]),
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/test',
                'access_code' => 'access_sub',
                'reference' => 'SUB_TEST123',
            ],
        ]),
    ]);

    $plan = PlatformPlan::create([
        'name' => 'Starter',
        'slug' => 'starter',
        'price' => 2000,
        'max_members' => 20,
        'features' => ['basic_contributions', 'online_payments'],
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $family = Family::factory()->create();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);

    $this->actingAs($admin)
        ->postJson(route('subscription.subscribe'), [
            'plan_id' => $plan->id,
        ])
        ->assertSuccessful()
        ->assertJsonStructure(['access_code', 'authorization_url', 'reference']);
});

it('prevents non-admin from subscribing', function () {
    $plan = PlatformPlan::create([
        'name' => 'Starter',
        'slug' => 'starter',
        'price' => 2000,
        'max_members' => 20,
        'features' => [],
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->postJson(route('subscription.subscribe'), [
            'plan_id' => $plan->id,
        ])
        ->assertForbidden();
});

it('prevents subscribing to free plan', function () {
    $freePlan = PlatformPlan::create([
        'name' => 'Free',
        'slug' => 'free',
        'price' => 0,
        'max_members' => 5,
        'features' => [],
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $family = Family::factory()->create();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);

    $this->actingAs($admin)
        ->postJson(route('subscription.subscribe'), [
            'plan_id' => $freePlan->id,
        ])
        ->assertUnprocessable();
});
