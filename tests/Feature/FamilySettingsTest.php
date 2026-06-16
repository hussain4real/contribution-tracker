<?php

declare(strict_types=1);

use App\Jobs\SyncPaystackSubaccount;
use App\Models\Family;
use App\Models\FamilyCategory;
use App\Models\PlatformPlan;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Cache::forget('paystack_banks');

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

it('derives the bank code from the selected bank name when the hidden code is missing', function () {
    Queue::fake();
    Http::fake([
        'https://api.paystack.co/bank*' => Http::response([
            'status' => true,
            'message' => 'Banks retrieved',
            'data' => [
                ['name' => 'Guaranty Trust Bank', 'code' => '058', 'active' => true],
                ['name' => 'First Bank of Nigeria', 'code' => '011', 'active' => true],
            ],
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
            'bank_code' => null,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($family->refresh()->bank_code)->toBe('058')
        ->and($family->hasBankDetails())->toBeTrue();

    Queue::assertPushed(SyncPaystackSubaccount::class);
});

it('requires selecting a known bank when bank details are missing a bank code', function () {
    Queue::fake();
    Http::fake([
        'https://api.paystack.co/bank*' => Http::response([
            'status' => true,
            'message' => 'Banks retrieved',
            'data' => [
                ['name' => 'Guaranty Trust Bank', 'code' => '058', 'active' => true],
            ],
        ]),
    ]);

    $family = Family::factory()->create();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);

    $this->actingAs($admin)
        ->put(route('family.settings.update'), [
            'name' => $family->name,
            'currency' => $family->currency,
            'due_day' => $family->due_day,
            'bank_name' => 'Unknown Trust Bank',
            'account_name' => 'Aminu Hussain',
            'account_number' => '0045502216',
            'bank_code' => null,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('bank_name');

    expect($family->refresh()->bank_code)->toBeNull();

    Queue::assertNotPushed(SyncPaystackSubaccount::class);
});

it('allows family settings due day at the end of the month', function () {
    $family = Family::factory()->create();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);

    $this->actingAs($admin)
        ->put(route('family.settings.update'), [
            'name' => $family->name,
            'currency' => $family->currency,
            'due_day' => 30,
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Family settings updated.');

    expect($family->refresh()->due_day)->toBe(30);
});

it('dispatches paystack subaccount sync only when complete bank details change', function () {
    Queue::fake();

    $family = Family::factory()->create([
        'bank_code' => '058',
        'account_number' => '0045502216',
    ]);
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

    Queue::assertNotPushed(SyncPaystackSubaccount::class);

    $this->actingAs($admin)
        ->put(route('family.settings.update'), [
            'name' => $family->name,
            'currency' => $family->currency,
            'due_day' => $family->due_day,
            'bank_name' => 'Guaranty Trust Bank',
            'account_name' => 'Aminu Hussain',
            'account_number' => '0045502217',
            'bank_code' => '058',
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Family settings updated.');

    Queue::assertPushed(SyncPaystackSubaccount::class);
});

it('shows family settings page for admin', function () {
    $family = Family::factory()->create([
        'bank_name' => 'Guaranty Trust Bank',
        'bank_code' => '058',
    ]);
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);
    $category = FamilyCategory::factory()->create([
        'family_id' => $family->id,
        'name' => 'Adults',
        'monthly_amount' => 4000,
        'sort_order' => 0,
    ]);
    User::factory()->member()->create([
        'family_id' => $family->id,
        'family_category_id' => $category->id,
    ]);

    $this->actingAs($admin)
        ->get(route('family.settings'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Family/Settings')
            ->has('family')
            ->has('categories', 1)
            ->where('family.bank_name', 'Guaranty Trust Bank')
            ->where('family.bank_code', '058')
            ->where('categories.0.name', 'Adults')
            ->where('categories.0.monthly_amount', 4000)
            ->where('categories.0.members_count', 1)
        );
});

it('denies non-admin access to family settings', function () {
    $family = Family::factory()->create();
    $member = User::factory()->create(['family_id' => $family->id]);

    $this->actingAs($member)
        ->get(route('family.settings'))
        ->assertForbidden();
});

it('denies non-admin updates to family settings', function () {
    $family = Family::factory()->create();
    $member = User::factory()->member()->create(['family_id' => $family->id]);

    $this->actingAs($member)
        ->put(route('family.settings.update'), [
            'name' => 'Updated Family',
            'currency' => '₦',
            'due_day' => 15,
        ])
        ->assertForbidden();

    expect($family->refresh()->name)->not->toBe('Updated Family');
});

it('denies non-admin access to banks list', function () {
    $family = Family::factory()->create();
    $member = User::factory()->create(['family_id' => $family->id]);

    $this->actingAs($member)
        ->getJson(route('family.banks'))
        ->assertForbidden();
});

it('returns an empty bank list when paystack does not return a successful response', function () {
    Http::fake([
        'https://api.paystack.co/bank*' => Http::response([
            'status' => false,
            'message' => 'Unavailable',
            'data' => [],
        ]),
    ]);

    $family = Family::factory()->create();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);

    $this->actingAs($admin)
        ->getJson(route('family.banks'))
        ->assertSuccessful()
        ->assertExactJson([]);
});

it('returns an empty bank list when paystack bank data is malformed', function () {
    Http::fake([
        'https://api.paystack.co/bank*' => Http::response([
            'status' => true,
            'message' => 'Banks retrieved',
            'data' => 'not-a-bank-list',
        ]),
    ]);

    $family = Family::factory()->create();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);

    $this->actingAs($admin)
        ->getJson(route('family.banks'))
        ->assertSuccessful()
        ->assertExactJson([]);
});

it('stores family categories with the next sort order', function () {
    $family = Family::factory()->create();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);
    FamilyCategory::factory()->create([
        'family_id' => $family->id,
        'sort_order' => 2,
    ]);

    $this->actingAs($admin)
        ->post(route('family.categories.store'), [
            'name' => 'Young Adults',
            'monthly_amount' => 2500,
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Category added.');

    $this->assertDatabaseHas('family_categories', [
        'family_id' => $family->id,
        'name' => 'Young Adults',
        'slug' => 'young-adults',
        'monthly_amount' => 2500,
        'sort_order' => 3,
    ]);
});

it('prevents non-admins from storing family categories', function () {
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->post(route('family.categories.store'), [
            'name' => 'Young Adults',
            'monthly_amount' => 2500,
        ])
        ->assertForbidden();
});

it('updates family categories in the admins family', function () {
    $family = Family::factory()->create();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);
    $category = FamilyCategory::factory()->create(['family_id' => $family->id]);

    $this->actingAs($admin)
        ->put(route('family.categories.update', $category), [
            'name' => 'Working Adults',
            'monthly_amount' => 4500,
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Category updated.');

    expect($category->refresh()->name)->toBe('Working Adults')
        ->and($category->slug)->toBe('working-adults')
        ->and($category->monthly_amount)->toBe(4500);
});

it('prevents category updates outside the admins family', function () {
    $admin = User::factory()->admin()->create();
    $category = FamilyCategory::factory()->create();

    $this->actingAs($admin)
        ->put(route('family.categories.update', $category), [
            'name' => 'Working Adults',
            'monthly_amount' => 4500,
        ])
        ->assertForbidden();
});

it('prevents deleting a category with active members', function () {
    $family = Family::factory()->create();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);
    $category = FamilyCategory::factory()->create(['family_id' => $family->id]);
    User::factory()->member()->create([
        'family_id' => $family->id,
        'family_category_id' => $category->id,
    ]);

    $this->actingAs($admin)
        ->delete(route('family.categories.destroy', $category))
        ->assertRedirect()
        ->assertSessionHas('error', 'Cannot delete a category with active members.');

    expect($category->refresh()->exists)->toBeTrue();
});

it('deletes empty categories in the admins family', function () {
    $family = Family::factory()->create();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);
    $category = FamilyCategory::factory()->create(['family_id' => $family->id]);

    $this->actingAs($admin)
        ->delete(route('family.categories.destroy', $category))
        ->assertRedirect()
        ->assertSessionHas('success', 'Category deleted.');

    expect(FamilyCategory::whereKey($category->id)->exists())->toBeFalse();
});

it('prevents deleting categories outside the admins family', function () {
    $admin = User::factory()->admin()->create();
    $category = FamilyCategory::factory()->create();

    $this->actingAs($admin)
        ->delete(route('family.categories.destroy', $category))
        ->assertForbidden();
});
