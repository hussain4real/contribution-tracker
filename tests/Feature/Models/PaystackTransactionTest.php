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
        'status' => TransactionStatus::Pending,
        'paystack_response' => ['status' => true],
        'metadata' => ['plan' => 'family'],
    ]);

    expect($transaction->type)->toBe(TransactionType::Subscription)
        ->and($transaction->status)->toBe(TransactionStatus::Pending)
        ->and($transaction->amount)->toBe(5000)
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
