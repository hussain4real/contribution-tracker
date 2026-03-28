<?php

use App\Models\Contribution;
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

    // Create a free plan so the subscription middleware doesn't block
    PlatformPlan::create([
        'name' => 'Free',
        'slug' => 'free',
        'price' => 0,
        'max_members' => 5,
        'features' => ['basic_contributions', 'manual_payments', 'online_payments'],
        'is_active' => true,
        'sort_order' => 0,
    ]);
});

it('shows the pay page for authenticated member', function () {
    $family = Family::factory()->create([
        'bank_name' => 'Kuda Bank',
        'bank_code' => '090267',
        'account_number' => '1234567890',
    ]);
    $member = User::factory()->create(['family_id' => $family->id]);

    $this->actingAs($member)
        ->get(route('pay.index'))
        ->assertSuccessful();
});

it('redirects guests from pay page', function () {
    $this->get(route('pay.index'))
        ->assertRedirect();
});

it('initiates a payment for selected contributions', function () {
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'message' => 'Authorization URL created',
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/test',
                'access_code' => 'access_test',
                'reference' => 'TXN_TEST123',
            ],
        ]),
    ]);

    $family = Family::factory()->create([
        'paystack_subaccount_code' => 'ACCT_test',
        'bank_name' => 'Kuda Bank',
        'bank_code' => '090267',
        'account_number' => '1234567890',
    ]);
    $member = User::factory()->create(['family_id' => $family->id]);

    $contribution = Contribution::factory()->create([
        'family_id' => $family->id,
        'user_id' => $member->id,
        'expected_amount' => 4000,
    ]);

    $this->actingAs($member)
        ->postJson(route('pay.initiate'), [
            'contribution_ids' => [$contribution->id],
        ])
        ->assertSuccessful()
        ->assertJsonStructure(['access_code', 'authorization_url', 'reference']);
});

it('fails to initiate without bank details', function () {
    $family = Family::factory()->create([
        'bank_name' => null,
        'bank_code' => null,
        'account_number' => null,
    ]);
    $member = User::factory()->create(['family_id' => $family->id]);

    $contribution = Contribution::factory()->create([
        'family_id' => $family->id,
        'user_id' => $member->id,
        'expected_amount' => 4000,
    ]);

    $this->actingAs($member)
        ->postJson(route('pay.initiate'), [
            'contribution_ids' => [$contribution->id],
        ])
        ->assertUnprocessable()
        ->assertJsonFragment(['message' => 'Online payments are not set up for your family. Ask your admin to configure bank details.']);
});

it('validates contribution_ids are required', function () {
    $family = Family::factory()->create([
        'bank_name' => 'Kuda Bank',
        'bank_code' => '090267',
        'account_number' => '1234567890',
    ]);
    $member = User::factory()->create(['family_id' => $family->id]);

    $this->actingAs($member)
        ->postJson(route('pay.initiate'), [
            'contribution_ids' => [],
        ])
        ->assertUnprocessable();
});
