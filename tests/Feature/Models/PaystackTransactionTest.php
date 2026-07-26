<?php

declare(strict_types=1);

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Family;
use App\Models\PaystackTransaction;
use App\Models\User;

it('casts transaction fields and exposes relationships', function () {
    $family = Family::factory()->create();
    $user = User::factory()->member()->create(['family_id' => $family->id]);

    $transaction = PaystackTransaction::create([
        'reference' => 'TXN_CASTS',
        'user_id' => $user->id,
        'family_id' => $family->id,
        'type' => TransactionType::Subscription,
        'amount' => '5000',
        'gross_amount_kobo' => '516000',
        'estimated_fee_kobo' => '16000',
        'actual_fee_kobo' => '15950',
        'settled_amount_kobo' => '500050',
        'fee_policy' => 'payer_pays',
        'status' => TransactionStatus::Pending,
        'paystack_response' => ['status' => true],
        'metadata' => ['plan' => 'family'],
    ]);

    expect($transaction->type)->toBe(TransactionType::Subscription)
        ->and($transaction->status)->toBe(TransactionStatus::Pending)
        ->and($transaction->amount)->toBe(5000)
        ->and($transaction->gross_amount_kobo)->toBe(516000)
        ->and($transaction->estimated_fee_kobo)->toBe(16000)
        ->and($transaction->actual_fee_kobo)->toBe(15950)
        ->and($transaction->settled_amount_kobo)->toBe(500050)
        ->and($transaction->fee_policy)->toBe('payer_pays')
        ->and($transaction->expectedGrossAmountKobo())->toBe(516000)
        ->and($transaction->contributionAmountKobo())->toBe(500000)
        ->and($transaction->paystack_response)->toBe(['status' => true])
        ->and($transaction->metadata)->toBe(['plan' => 'family'])
        ->and($transaction->user()->firstOrFail()->is($user))->toBeTrue()
        ->and($transaction->family()->firstOrFail()->is($family))->toBeTrue();
});

it('reports the current transaction status', function (TransactionStatus $status, bool $pending, bool $successful, bool $failed) {
    $transaction = new PaystackTransaction(['status' => $status]);

    expect($transaction->isPending())->toBe($pending)
        ->and($transaction->isSuccessful())->toBe($successful)
        ->and($transaction->isFailed())->toBe($failed);
})->with([
    'pending' => [TransactionStatus::Pending, true, false, false],
    'success' => [TransactionStatus::Success, false, true, false],
    'failed' => [TransactionStatus::Failed, false, false, true],
    'abandoned' => [TransactionStatus::Abandoned, false, false, false],
]);
