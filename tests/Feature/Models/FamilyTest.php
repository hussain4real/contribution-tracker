<?php

declare(strict_types=1);

use App\Models\Contribution;
use App\Models\Expense;
use App\Models\Family;
use App\Models\FamilyCategory;
use App\Models\FamilyInvitation;
use App\Models\FundAdjustment;
use App\Models\PaymentBatch;
use App\Models\PlatformPlan;
use App\Models\User;

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
        ->and($family->trial_ends_at?->toDateTimeString())->toBe('2026-05-31 23:59:59')
        ->and($family->max_members)->toBe(25)
        ->and($family->suspended_at?->toDateTimeString())->toBe('2026-05-11 09:00:00')
        ->and($family->current_period_end?->toDateTimeString())->toBe('2026-06-11 09:00:00')
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
    $category = FamilyCategory::query()
        ->where('family_id', $family->id)
        ->where('slug', 'employed')
        ->firstOrFail();
    $contribution = Contribution::factory()->create(['family_id' => $family->id, 'user_id' => $member->id]);
    $expense = Expense::factory()->create(['family_id' => $family->id]);
    $fundAdjustment = FundAdjustment::factory()->create(['family_id' => $family->id]);
    $paymentBatch = PaymentBatch::factory()->create(['family_id' => $family->id]);
    $invitation = FamilyInvitation::factory()->create(['family_id' => $family->id]);

    expect($family->owner()->firstOrFail()->is($owner))->toBeTrue()
        ->and($family->platformPlan()->firstOrFail()->is($plan))->toBeTrue()
        ->and($family->members()->firstOrFail()->is($member))->toBeTrue()
        ->and($family->categories()->firstOrFail()->is($category))->toBeTrue()
        ->and($family->contributions()->firstOrFail()->is($contribution))->toBeTrue()
        ->and($family->expenses()->firstOrFail()->is($expense))->toBeTrue()
        ->and($family->fundAdjustments()->firstOrFail()->is($fundAdjustment))->toBeTrue()
        ->and($family->paymentBatches()->firstOrFail()->is($paymentBatch))->toBeTrue()
        ->and($family->invitations()->firstOrFail()->is($invitation))->toBeTrue();
});

it('prevents deleting families with any kind of financial history', function () {
    $contributionFamily = Family::factory()->create();
    $contributionMember = User::factory()->member()->create(['family_id' => $contributionFamily->id]);
    Contribution::factory()->forUser($contributionMember)->create(['family_id' => $contributionFamily->id]);

    $receiptFamily = Family::factory()->create();
    PaymentBatch::factory()->create(['family_id' => $receiptFamily->id]);

    $expenseFamily = Family::factory()->create();
    Expense::factory()->create(['family_id' => $expenseFamily->id]);

    $adjustmentFamily = Family::factory()->create();
    FundAdjustment::factory()->create(['family_id' => $adjustmentFamily->id]);

    expect(fn () => $contributionFamily->delete())->toThrow(LogicException::class)
        ->and(fn () => $receiptFamily->delete())->toThrow(LogicException::class)
        ->and(fn () => $expenseFamily->delete())->toThrow(LogicException::class)
        ->and(fn () => $adjustmentFamily->delete())->toThrow(LogicException::class);
});
