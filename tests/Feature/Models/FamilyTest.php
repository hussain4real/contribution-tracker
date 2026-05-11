<?php

use App\Models\Contribution;
use App\Models\Expense;
use App\Models\Family;
use App\Models\FamilyCategory;
use App\Models\FamilyInvitation;
use App\Models\FundAdjustment;
use App\Models\PlatformPlan;
use App\Models\User;
use Carbon\Carbon;

it('casts family fields and reports status helpers', function () {
    $family = Family::factory()->create([
        'due_day' => '15',
        'trial_ends_at' => '2026-05-31 23:59:59',
        'max_members' => '25',
        'suspended_at' => '2026-05-11 09:00:00',
        'current_period_end' => '2026-06-11 09:00:00',
        'paystack_subaccount_code' => 'ACCT_test',
        'subscription_status' => 'active',
        'bank_code' => '058',
        'account_number' => '0123456789',
    ]);

    expect($family->due_day)->toBe(15)
        ->and($family->trial_ends_at)->toBeInstanceOf(Carbon::class)
        ->and($family->max_members)->toBe(25)
        ->and($family->suspended_at)->toBeInstanceOf(Carbon::class)
        ->and($family->current_period_end)->toBeInstanceOf(Carbon::class)
        ->and($family->isSuspended())->toBeTrue()
        ->and($family->hasPaystackSubaccount())->toBeTrue()
        ->and($family->hasActiveSubscription())->toBeTrue()
        ->and($family->hasBankDetails())->toBeTrue();
});

it('reports false status helpers when optional family fields are missing', function () {
    $family = Family::factory()->create();

    expect($family->isSuspended())->toBeFalse()
        ->and($family->hasPaystackSubaccount())->toBeFalse()
        ->and($family->hasActiveSubscription())->toBeFalse()
        ->and($family->hasBankDetails())->toBeFalse();
});

it('exposes family ownership, plan, and child relationships', function () {
    $owner = User::factory()->admin()->create();
    $plan = PlatformPlan::create([
        'name' => 'Family',
        'slug' => 'family',
        'price' => 5000,
        'max_members' => 25,
        'paystack_plan_code' => 'PLN_family',
        'features' => ['reports'],
        'is_active' => true,
        'sort_order' => 1,
    ]);
    $family = Family::factory()->create([
        'created_by' => $owner->id,
        'platform_plan_id' => $plan->id,
    ]);
    $member = User::factory()->member()->create(['family_id' => $family->id]);
    $category = FamilyCategory::factory()->create(['family_id' => $family->id]);
    $contribution = Contribution::factory()->create(['family_id' => $family->id, 'user_id' => $member->id]);
    $expense = Expense::factory()->create(['family_id' => $family->id]);
    $fundAdjustment = FundAdjustment::factory()->create(['family_id' => $family->id]);
    $invitation = FamilyInvitation::factory()->create(['family_id' => $family->id]);

    expect($family->owner->is($owner))->toBeTrue()
        ->and($family->platformPlan->is($plan))->toBeTrue()
        ->and($family->members->first()->is($member))->toBeTrue()
        ->and($family->categories->first()->is($category))->toBeTrue()
        ->and($family->contributions->first()->is($contribution))->toBeTrue()
        ->and($family->expenses->first()->is($expense))->toBeTrue()
        ->and($family->fundAdjustments->first()->is($fundAdjustment))->toBeTrue()
        ->and($family->invitations->first()->is($invitation))->toBeTrue();
});
