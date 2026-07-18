<?php

declare(strict_types=1);

use App\Enums\PaymentSource;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\AuditEvent;
use App\Models\Expense;
use App\Models\Family;
use App\Models\FamilyCategory;
use App\Models\PaymentBatch;
use App\Models\PaystackTransaction;
use App\Models\User;
use App\Services\PaystackContributionSettlementService;
use App\Support\EffectiveLedger;

function initiatedPaystackContribution(int $grossAmountKobo = 400000): PaystackTransaction
{
    $family = Family::factory()->create();
    $category = FamilyCategory::factory()->create([
        'family_id' => $family->id,
        'name' => 'Adult',
        'slug' => 'adult',
        'monthly_amount' => 4000,
    ]);
    $member = User::factory()->member()->create([
        'family_id' => $family->id,
        'family_category_id' => $category->id,
    ]);

    return PaystackTransaction::query()->create([
        'reference' => 'paystack-'.fake()->uuid(),
        'user_id' => $member->id,
        'family_id' => $family->id,
        'type' => TransactionType::Contribution,
        'amount' => 4000,
        'gross_amount_kobo' => $grossAmountKobo,
        'status' => TransactionStatus::Initiated,
        'fee_policy' => 'payer_pays',
        'metadata' => [
            'target_year' => now()->year,
            'target_month' => now()->month,
        ],
    ]);
}

it('verifies and allocates Paystack money before marking it posted and is replay safe', function () {
    $transaction = initiatedPaystackContribution();
    $service = app(PaystackContributionSettlementService::class);
    $providerData = [
        'status' => 'success',
        'reference' => $transaction->reference,
        'amount' => 400000,
        'fees' => 0,
        'paid_at' => now()->toIso8601String(),
    ];

    $settled = $service->settle($transaction->reference, $providerData);
    $replayed = $service->settle($transaction->reference, $providerData);

    expect($settled->status)->toBe(TransactionStatus::Allocated)
        ->and($settled->verified_at)->not->toBeNull()
        ->and($settled->allocated_at)->not->toBeNull()
        ->and($settled->payment_batch_id)->not->toBeNull()
        ->and($settled->paymentBatch?->source)->toBe(PaymentSource::Paystack)
        ->and($settled->paymentBatch?->total_amount)->toBe(4000)
        ->and($replayed->payment_batch_id)->toBe($settled->payment_batch_id)
        ->and(PaymentBatch::query()->where('family_id', $transaction->family_id)->count())->toBe(1)
        ->and(app(EffectiveLedger::class)->paymentsTotal($transaction->family_id))->toBe(4000)
        ->and(AuditEvent::query()->where('action', 'paystack.state_changed')->count())->toBe(2);
});

it('posts a fee shortfall as an expense so the effective ledger matches provider settlement', function () {
    $transaction = initiatedPaystackContribution();

    $settled = app(PaystackContributionSettlementService::class)->settle($transaction->reference, [
        'status' => 'success',
        'reference' => $transaction->reference,
        'amount' => 400000,
        'fees' => 6000,
        'paid_at' => now()->toIso8601String(),
    ]);

    expect($settled->feeExpense?->amount)->toBe(60)
        ->and($settled->settled_amount_kobo)->toBe(394000)
        ->and(app(EffectiveLedger::class)->balance($transaction->family_id))->toBe(3940);
});

it('fails closed on a provider amount mismatch without posting money', function () {
    $transaction = initiatedPaystackContribution();

    expect(fn () => app(PaystackContributionSettlementService::class)->settle($transaction->reference, [
        'status' => 'success',
        'reference' => $transaction->reference,
        'amount' => 399900,
        'fees' => 0,
    ]))->toThrow(RuntimeException::class, 'does not match');

    $transaction->refresh();

    expect($transaction->status)->toBe(TransactionStatus::Failed)
        ->and($transaction->payment_batch_id)->toBeNull()
        ->and($transaction->allocated_at)->toBeNull()
        ->and($transaction->failed_at)->not->toBeNull()
        ->and(app(EffectiveLedger::class)->paymentsTotal($transaction->family_id))->toBe(0);
});

it('rejects non-contribution transactions and unsuccessful provider verification', function () {
    $nonContribution = initiatedPaystackContribution();
    $nonContribution->forceFill(['type' => TransactionType::Subscription])->save();

    expect(fn () => app(PaystackContributionSettlementService::class)->settle($nonContribution->reference, [
        'status' => 'success',
        'amount' => 400000,
    ]))->toThrow(RuntimeException::class, 'not a contribution');

    $unverified = initiatedPaystackContribution();

    expect(fn () => app(PaystackContributionSettlementService::class)->settle($unverified->reference, [
        'status' => 'failed',
        'amount' => 400000,
    ]))->toThrow(RuntimeException::class, 'did not verify');
});

it('derives a provider fee when Paystack omits both actual and estimated fees', function () {
    $transaction = initiatedPaystackContribution(416000);

    $settled = app(PaystackContributionSettlementService::class)->settle($transaction->reference, [
        'status' => 'success',
        'amount' => 416000,
    ]);

    expect($settled->actual_fee_kobo)->toBeNull()
        ->and($settled->settled_amount_kobo)->toBe(400000)
        ->and($settled->fee_expense_id)->toBeNull();
});

it('reuses an existing fee expense when a settlement is retried before allocation', function () {
    $transaction = initiatedPaystackContribution();
    $member = User::query()->findOrFail($transaction->user_id);
    $expense = Expense::factory()->recordedBy($member)->create([
        'family_id' => $transaction->family_id,
        'amount' => 60,
    ]);
    $transaction->forceFill(['fee_expense_id' => $expense->id])->save();

    $settled = app(PaystackContributionSettlementService::class)->settle($transaction->reference, [
        'status' => 'success',
        'amount' => 400000,
        'fees' => 6000,
    ]);

    expect($settled->fee_expense_id)->toBe($expense->id)
        ->and(Expense::query()->where('family_id', $transaction->family_id)->count())->toBe(1);
});

it('rejects non-numeric provider charge amounts', function () {
    $transaction = initiatedPaystackContribution();

    expect(fn () => app(PaystackContributionSettlementService::class)->settle($transaction->reference, [
        'status' => 'success',
        'amount' => 'invalid',
    ]))->toThrow(RuntimeException::class, 'valid charge amount');
});
