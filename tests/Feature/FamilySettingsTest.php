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

    PlatformPlan::create([
        'name' => 'Free',
        'slug' => 'free',
        'price' => 0,
        'max_members' => 5,
        'features' => ['basic_contributions', 'manual_payments'],
        'is_active' => true,
        'sort_order' => 0,
    ]);
});

it('returns bank list for authenticated admin', function () {
    Http::fake([
        'https://api.paystack.co/bank*' => Http::response([
            'status' => true,
            'message' => 'Banks retrieved',
            'data' => [
                ['name' => 'Guaranty Trust Bank', 'code' => '058', 'active' => true],
                ['name' => 'First Bank of Nigeria', 'code' => '011', 'active' => true],
                ['name' => 'Old Inactive Bank', 'code' => '999', 'active' => false],
            ],
        ]),
    ]);

    $family = Family::factory()->create();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);

    $response = $this->actingAs($admin)
        ->getJson(route('family.banks'));

    $response->assertSuccessful()
        ->assertJsonCount(2)
        ->assertJsonFragment(['name' => 'First Bank of Nigeria', 'code' => '011'])
        ->assertJsonFragment(['name' => 'Guaranty Trust Bank', 'code' => '058'])
        ->assertJsonMissing(['name' => 'Old Inactive Bank']);
});

it('updates family settings with bank code', function () {
    Http::fake([
        'https://api.paystack.co/subaccount' => Http::response([
            'status' => true,
            'data' => ['subaccount_code' => 'ACCT_test123'],
        ]),
    ]);

    $family = Family::factory()->create();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);

    $this->actingAs($admin)
        ->put(route('family.settings.update'), [
            'name' => $family->name,
            'currency' => $family->currency,
            'due_day' => $family->due_day,
            'bank_name' => 'Guaranty Trust Bank',
            'account_name' => 'Aminu Hussain',
            'account_number' => '0045502216',
            'bank_code' => '058',
        ])
        ->assertRedirect();

    $family->refresh();
    expect($family->bank_name)->toBe('Guaranty Trust Bank')
        ->and($family->bank_code)->toBe('058')
        ->and($family->account_number)->toBe('0045502216');
});

it('shows family settings page for admin', function () {
    $family = Family::factory()->create([
        'bank_name' => 'Guaranty Trust Bank',
        'bank_code' => '058',
    ]);
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);

    $this->actingAs($admin)
        ->get(route('family.settings'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Family/Settings')
            ->has('family')
            ->where('family.bank_name', 'Guaranty Trust Bank')
            ->where('family.bank_code', '058')
        );
});

it('denies non-admin access to family settings', function () {
    $family = Family::factory()->create();
    $member = User::factory()->create(['family_id' => $family->id]);

    $this->actingAs($member)
        ->get(route('family.settings'))
        ->assertForbidden();
});

it('denies non-admin access to banks list', function () {
    $family = Family::factory()->create();
    $member = User::factory()->create(['family_id' => $family->id]);

    $this->actingAs($member)
        ->getJson(route('family.banks'))
        ->assertForbidden();
});
