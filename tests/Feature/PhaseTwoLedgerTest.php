<?php

declare(strict_types=1);

use App\Actions\CorrectExpense;
use App\Actions\CorrectFundAdjustment;
use App\Actions\CorrectPaymentBatch;
use App\Actions\ReverseExpense;
use App\Actions\ReverseFundAdjustment;
use App\Actions\ReversePaymentBatch;
use App\Enums\PaymentMethod;
use App\Enums\PaymentSource;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Contribution;
use App\Models\Expense;
use App\Models\Family;
use App\Models\FamilyCategory;
use App\Models\FinancialReversal;
use App\Models\FundAdjustment;
use App\Models\Payment;
use App\Models\PaymentBatch;
use App\Models\User;
use App\Policies\PaymentBatchPolicy;
use App\Policies\PaymentPolicy;
use App\Services\PaymentAllocationService;
use App\Support\EffectiveLedger;

/** @return array{Family, User, User} */
function ledgerFixture(): array
{
    $family = Family::factory()->create();
    $category = FamilyCategory::factory()->create([
        'family_id' => $family->id,
        'name' => 'Adult',
        'slug' => 'adult',
        'monthly_amount' => 4000,
    ]);
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);
    $member = User::factory()->member()->create([
        'family_id' => $family->id,
        'family_category_id' => $category->id,
    ]);

    return [$family, $admin, $member];
}

it('posts atomic batches with family receipt sequencing and idempotency', function () {
    [$family, $admin, $member] = ledgerFixture();
    $service = app(PaymentAllocationService::class);

    $first = $service->createBatch(
        $member,
        4000,
        now(),
        $admin,
        family: $family,
        idempotencyKey: 'manual:receipt-one',
    );
    $replayed = $service->createBatch(
        $member,
        4000,
        now(),
        $admin,
        family: $family,
        idempotencyKey: 'manual:receipt-one',
    );
    $second = $service->createBatch(
        $member,
        1000,
        now(),
        $admin,
        family: $family,
        idempotencyKey: 'manual:receipt-two',
    );

    expect($replayed->id)->toBe($first->id)
        ->and(PaymentBatch::query()->where('family_id', $family->id)->count())->toBe(2)
        ->and($first->receipt_number)->toBe(1)
        ->and($second->receipt_number)->toBe(2)
        ->and($first->allocations->sum('amount'))->toBe(4000)
        ->and($second->allocations->sum('amount'))->toBe(1000)
        ->and(AuditEvent::query()->where('action', 'payment.posted')->count())->toBe(2);
});

it('keeps posted rows immutable and removes reversed entries from every effective total', function () {
    [$family, $admin, $member] = ledgerFixture();
    $batch = app(PaymentAllocationService::class)->createBatch(
        $member,
        4000,
        now(),
        $admin,
        family: $family,
        idempotencyKey: 'manual:immutable',
    );
    $expense = Expense::factory()->recordedBy($admin)->create(['family_id' => $family->id, 'amount' => 600]);
    $adjustment = FundAdjustment::factory()->recordedBy($admin)->create(['family_id' => $family->id, 'amount' => 100]);
    $ledger = app(EffectiveLedger::class);

    expect($ledger->balance($family->id))->toBe(3500)
        ->and(fn () => $batch->update(['notes' => 'changed']))->toThrow(LogicException::class)
        ->and(fn () => $batch->allocations->firstOrFail()->delete())->toThrow(LogicException::class)
        ->and(fn () => $expense->delete())->toThrow(LogicException::class)
        ->and(fn () => $adjustment->update(['amount' => 500]))->toThrow(LogicException::class);

    $paymentReversal = app(ReversePaymentBatch::class)->handle($batch, $admin, 'Duplicate receipt');
    app(ReverseExpense::class)->handle($expense, $admin, 'Expense entered twice');
    app(ReverseFundAdjustment::class)->handle($adjustment, $admin, 'Opening balance correction');

    expect($ledger->paymentsTotal($family->id))->toBe(0)
        ->and($ledger->expensesTotal($family->id))->toBe(0)
        ->and($ledger->adjustmentsTotal($family->id))->toBe(0)
        ->and($ledger->balance($family->id))->toBe(0)
        ->and(FinancialReversal::query()->where('family_id', $family->id)->count())->toBe(3)
        ->and($paymentReversal->request_id)->not->toBeEmpty()
        ->and(AuditEvent::query()->where('action', 'financial.reversed')->count())->toBe(3);
});

it('corrects a receipt by linking a replacement without shifting its contribution allocation', function () {
    [$family, $admin, $member] = ledgerFixture();
    $original = app(PaymentAllocationService::class)->createBatch(
        $member,
        4000,
        now(),
        $admin,
        family: $family,
        idempotencyKey: 'manual:original',
    );
    $originalContributionId = $original->allocations->firstOrFail()->contribution_id;

    $replacement = app(CorrectPaymentBatch::class)->handle(
        original: $original,
        actor: $admin,
        reason: 'Amount was overstated',
        amount: 3500,
        paidAt: now(),
        method: PaymentMethod::BankTransfer,
        idempotencyKey: 'manual:replacement',
    );
    $reversal = $original->reversal()->firstOrFail();
    $contribution = Contribution::query()->findOrFail($originalContributionId);

    expect($reversal->replacement_type)->toBe(PaymentBatch::MORPH_TYPE)
        ->and($reversal->replacement_id)->toBe($replacement->id)
        ->and($replacement->allocations->firstOrFail()->contribution_id)->toBe($originalContributionId)
        ->and(app(EffectiveLedger::class)->paymentsTotal($family->id))->toBe(3500)
        ->and($contribution->refresh()->total_paid)->toBe(3500)
        ->and($contribution->balance)->toBe(500);
});

it('corrects expenses and fund adjustments using linked append-only replacements', function () {
    [$family, $admin] = ledgerFixture();
    $expense = Expense::factory()->recordedBy($admin)->create([
        'amount' => 900,
        'description' => 'Original expense',
    ]);
    $adjustment = FundAdjustment::factory()->recordedBy($admin)->create([
        'amount' => 1200,
        'description' => 'Original adjustment',
    ]);

    $replacementExpense = app(CorrectExpense::class)->handle(
        original: $expense,
        actor: $admin,
        reason: 'Wrong expense amount',
        amount: 700,
        description: 'Corrected expense',
        spentAt: now(),
    );
    $replacementAdjustment = app(CorrectFundAdjustment::class)->handle(
        original: $adjustment,
        actor: $admin,
        reason: 'Wrong adjustment amount',
        amount: 1000,
        description: 'Corrected adjustment',
        recordedAt: now(),
    );

    $expenseReversal = $expense->reversal()->firstOrFail();
    $adjustmentReversal = $adjustment->reversal()->firstOrFail();

    expect($expenseReversal->family()->firstOrFail()->is($family))->toBeTrue()
        ->and($expenseReversal->reverser()->firstOrFail()->is($admin))->toBeTrue()
        ->and($expenseReversal->reversible()->firstOrFail()->is($expense))->toBeTrue()
        ->and($expenseReversal->replacement()->firstOrFail()->is($replacementExpense))->toBeTrue()
        ->and($adjustmentReversal->replacement()->firstOrFail()->is($replacementAdjustment))->toBeTrue()
        ->and(app(EffectiveLedger::class)->expensesTotal($family->id))->toBe(700)
        ->and(app(EffectiveLedger::class)->adjustmentsTotal($family->id))->toBe(1000)
        ->and(fn () => $expenseReversal->update(['reason' => 'Changed']))->toThrow(LogicException::class)
        ->and(fn () => $expenseReversal->delete())->toThrow(LogicException::class);
});

it('returns existing reversals and rejects invalid reasons and cross-family replacements', function () {
    [$family, $admin] = ledgerFixture();
    $otherFamily = Family::factory()->create();
    $expense = Expense::factory()->recordedBy($admin)->create();
    $adjustment = FundAdjustment::factory()->recordedBy($admin)->create();
    $batch = PaymentBatch::factory()->create([
        'family_id' => $family->id,
        'recorded_by' => $admin->id,
    ]);

    $expenseReversal = app(ReverseExpense::class)->handle($expense, $admin, 'Duplicate');
    $adjustmentReversal = app(ReverseFundAdjustment::class)->handle($adjustment, $admin, 'Duplicate');
    $batchReversal = app(ReversePaymentBatch::class)->handle($batch, $admin, 'Duplicate');

    expect(app(ReverseExpense::class)->handle($expense, $admin, 'Ignored')->is($expenseReversal))->toBeTrue()
        ->and(app(ReverseFundAdjustment::class)->handle($adjustment, $admin, 'Ignored')->is($adjustmentReversal))->toBeTrue()
        ->and(app(ReversePaymentBatch::class)->handle($batch, $admin, 'Ignored')->is($batchReversal))->toBeTrue();

    $unreversedExpense = Expense::factory()->recordedBy($admin)->create();
    $unreversedAdjustment = FundAdjustment::factory()->recordedBy($admin)->create();
    $unreversedBatch = PaymentBatch::factory()->create([
        'family_id' => $family->id,
        'recorded_by' => $admin->id,
        'receipt_number' => 2,
    ]);
    $otherExpense = Expense::factory()->create(['family_id' => $otherFamily->id]);
    $otherAdjustment = FundAdjustment::factory()->create(['family_id' => $otherFamily->id]);
    $otherBatch = PaymentBatch::factory()->create(['family_id' => $otherFamily->id]);

    expect(fn () => app(ReverseExpense::class)->handle($unreversedExpense, $admin, '  '))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(ReverseFundAdjustment::class)->handle($unreversedAdjustment, $admin, ''))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(ReversePaymentBatch::class)->handle($unreversedBatch, $admin, ''))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(ReverseExpense::class)->handle($unreversedExpense, $admin, 'Correction', $otherExpense))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(ReverseFundAdjustment::class)->handle($unreversedAdjustment, $admin, 'Correction', $otherAdjustment))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(ReversePaymentBatch::class)->handle($unreversedBatch, $admin, 'Correction', $otherBatch))
        ->toThrow(InvalidArgumentException::class);
});

it('validates receipt correction and allocation invariants', function () {
    [$family, $admin, $member] = ledgerFixture();
    $service = app(PaymentAllocationService::class);
    $receiptWithoutMembership = PaymentBatch::factory()->create([
        'family_id' => $family->id,
        'family_membership_id' => null,
        'recorded_by' => $admin->id,
    ]);

    expect(fn () => app(CorrectPaymentBatch::class)->handle(
        original: $receiptWithoutMembership,
        actor: $admin,
        reason: 'Correction',
        amount: 100,
        paidAt: now(),
        method: PaymentMethod::Cash,
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->createBatch($member, 0, now(), $admin, family: $family))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->createBatch($member, 32000, now(), $admin, family: $family))
        ->toThrow(InvalidArgumentException::class);

    $original = $service->createBatch(
        $member,
        4000,
        now(),
        $admin,
        family: $family,
        idempotencyKey: 'membership-one',
    );
    $otherMember = User::factory()->member()->create([
        'family_id' => $family->id,
        'family_category_id' => $member->family_category_id,
    ]);

    expect(fn () => $service->createBatch(
        $otherMember,
        100,
        now(),
        $admin,
        family: $family,
        idempotencyKey: 'wrong-membership-replacement',
        replaces: $original,
    ))->toThrow(InvalidArgumentException::class);

    $nonPayingMember = User::factory()->member()->nonPaying()->create(['family_id' => $family->id]);

    expect(fn () => $service->createBatch(
        $nonPayingMember,
        100,
        now(),
        $admin,
        family: $family,
        idempotencyKey: 'no-category',
    ))->toThrow(InvalidArgumentException::class);
});

it('exposes ledger model relationships, scopes, labels, and policy boundaries', function () {
    [$family, $admin, $member] = ledgerFixture();
    $batch = app(PaymentAllocationService::class)->createBatch(
        $member,
        4000,
        now(),
        $admin,
        family: $family,
        idempotencyKey: 'relationship-receipt',
    );
    $contribution = Contribution::query()->findOrFail(
        $batch->allocations->firstOrFail()->contribution_id,
    );
    $event = AuditEvent::query()
        ->where('auditable_type', PaymentBatch::MORPH_TYPE)
        ->where('auditable_id', $batch->id)
        ->firstOrFail();

    expect($batch->recorder()->firstOrFail()->is($admin))->toBeTrue()
        ->and($batch->load('reversal')->isReversed())->toBeFalse()
        ->and(PaymentBatch::query()->effective()->whereKey($batch->id)->exists())->toBeTrue()
        ->and($family->paymentBatches()->whereKey($batch->id)->exists())->toBeTrue()
        ->and($contribution->familyCategory()->firstOrFail()->id)->toBe($member->family_category_id)
        ->and($contribution->allPayments()->count())->toBe(1)
        ->and($event->family()->firstOrFail()->is($family))->toBeTrue()
        ->and($event->actor()->firstOrFail()->is($admin))->toBeTrue()
        ->and($event->auditable()->firstOrFail()->is($batch))->toBeTrue()
        ->and(fn () => $event->update(['action' => 'changed']))->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class)
        ->and(app(PaymentBatchPolicy::class)->view($member, $batch))->toBeTrue()
        ->and(app(PaymentBatchPolicy::class)->view(User::factory()->create(), $batch))->toBeFalse()
        ->and(PaymentSource::Manual->label())->toBe('Manual')
        ->and(PaymentSource::Paystack->label())->toBe('Paystack')
        ->and(PaymentSource::Backfill->label())->toBe('Historical Backfill')
        ->and(PaymentSource::Correction->label())->toBe('Correction');

    $orphanPayment = new Payment;

    expect(app(PaymentPolicy::class)->view($admin, $orphanPayment))->toBeFalse();
});

it('uses the receipt family role for viewing and reversing multi-family batches', function () {
    $currentFamily = Family::factory()->create();
    $batchFamily = Family::factory()->create();
    $currentAdmin = User::factory()->admin()->create(['family_id' => $currentFamily->id]);
    $currentAdmin->ensureFamilyMembership($batchFamily, Role::Member);
    $batchFamilyMember = User::factory()->member()->create(['family_id' => $batchFamily->id]);
    $batch = PaymentBatch::factory()->create([
        'family_id' => $batchFamily->id,
        'family_membership_id' => $batchFamilyMember->membershipForFamily($batchFamily)?->id,
        'recorded_by' => $batchFamilyMember->id,
    ]);
    $batchFamilyAdmin = User::factory()->member()->create(['family_id' => $currentFamily->id]);
    $batchFamilyAdmin->ensureFamilyMembership($batchFamily, Role::Admin);
    $userWithoutMembership = User::factory()->member()->create(['family_id' => $currentFamily->id]);
    $userWithoutMembership->forceFill(['current_family_id' => $batchFamily->id])->save();
    $policy = app(PaymentBatchPolicy::class);

    expect($policy->view($currentAdmin, $batch))->toBeFalse()
        ->and($policy->view($userWithoutMembership, $batch))->toBeFalse()
        ->and($policy->reverse($currentAdmin, $batch))->toBeFalse()
        ->and($policy->view($batchFamilyAdmin, $batch))->toBeFalse()
        ->and($policy->reverse($batchFamilyAdmin, $batch))->toBeFalse();

    $this->actingAs($currentAdmin)
        ->post(route('payment-batches.reverse', [
            'current_family' => $currentFamily->slug,
            'payment_batch' => $batch,
        ]), ['reason' => 'Must not cross families'])
        ->assertForbidden();

    expect($batch->reversal()->exists())->toBeFalse();

    $batchFamilyAdmin->switchFamily($batchFamily);

    expect($policy->view($batchFamilyAdmin, $batch))->toBeTrue()
        ->and($policy->reverse($batchFamilyAdmin, $batch))->toBeTrue();
});
