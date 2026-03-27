<?php

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\PaystackTransaction;
use App\Models\User;

beforeEach(function () {
    config([
        'services.paystack.secret_key' => 'sk_test_secret',
        'services.paystack.base_url' => 'https://api.paystack.co',
        'services.paystack.webhook_secret' => 'whsec_test123',
    ]);
});

function signPayload(string $payload): string
{
    return hash_hmac('sha512', $payload, 'whsec_test123');
}

it('rejects webhooks with invalid signature', function () {
    $payload = json_encode(['event' => 'charge.success', 'data' => []]);

    $this->postJson(route('webhooks.paystack'), json_decode($payload, true), [
        'X-Paystack-Signature' => 'invalid',
        'Content-Type' => 'application/json',
    ])->assertForbidden();
});

it('processes charge.success for contribution payment', function () {
    $family = Family::factory()->create([
        'paystack_subaccount_code' => 'ACCT_test',
    ]);
    $member = User::factory()->create(['family_id' => $family->id]);

    $contribution = Contribution::factory()->create([
        'family_id' => $family->id,
        'user_id' => $member->id,
        'expected_amount' => 4000,
        'year' => now()->year,
        'month' => now()->month,
    ]);

    $transaction = PaystackTransaction::create([
        'reference' => 'TXN_TEST001',
        'user_id' => $member->id,
        'family_id' => $family->id,
        'type' => TransactionType::Contribution,
        'amount' => 4000,
        'status' => TransactionStatus::Pending,
        'metadata' => [
            'contribution_ids' => [$contribution->id],
            'target_year' => $contribution->year,
            'target_month' => $contribution->month,
        ],
    ]);

    $payload = json_encode([
        'event' => 'charge.success',
        'data' => [
            'reference' => 'TXN_TEST001',
            'amount' => 400000, // kobo
            'paid_at' => now()->toDateString(),
            'status' => 'success',
        ],
    ]);

    $this->postJson(route('webhooks.paystack'), json_decode($payload, true), [
        'X-Paystack-Signature' => signPayload($payload),
    ])->assertSuccessful();

    $transaction->refresh();
    expect($transaction->status)->toBe(TransactionStatus::Success);
});

it('prevents double processing of charge.success', function () {
    $family = Family::factory()->create();
    $member = User::factory()->create(['family_id' => $family->id]);

    $transaction = PaystackTransaction::create([
        'reference' => 'TXN_DOUBLE',
        'user_id' => $member->id,
        'family_id' => $family->id,
        'type' => TransactionType::Contribution,
        'amount' => 4000,
        'status' => TransactionStatus::Success, // already processed
    ]);

    $payload = json_encode([
        'event' => 'charge.success',
        'data' => [
            'reference' => 'TXN_DOUBLE',
            'amount' => 400000,
        ],
    ]);

    $this->postJson(route('webhooks.paystack'), json_decode($payload, true), [
        'X-Paystack-Signature' => signPayload($payload),
    ])->assertSuccessful();

    // Should still be success, not re-processed
    $transaction->refresh();
    expect($transaction->status)->toBe(TransactionStatus::Success);
});

it('rejects charge.success with amount mismatch', function () {
    $family = Family::factory()->create();
    $member = User::factory()->create(['family_id' => $family->id]);

    PaystackTransaction::create([
        'reference' => 'TXN_MISMATCH',
        'user_id' => $member->id,
        'family_id' => $family->id,
        'type' => TransactionType::Contribution,
        'amount' => 4000,
        'status' => TransactionStatus::Pending,
    ]);

    $payload = json_encode([
        'event' => 'charge.success',
        'data' => [
            'reference' => 'TXN_MISMATCH',
            'amount' => 200000, // Wrong amount (2000 Naira in kobo, expected 4000)
        ],
    ]);

    $this->postJson(route('webhooks.paystack'), json_decode($payload, true), [
        'X-Paystack-Signature' => signPayload($payload),
    ])->assertStatus(400);
});

it('handles subscription.create event', function () {
    $family = Family::factory()->create([
        'paystack_customer_code' => 'CUS_test123',
    ]);

    $payload = json_encode([
        'event' => 'subscription.create',
        'data' => [
            'subscription_code' => 'SUB_abc123',
            'email_token' => 'tok_test456',
            'customer' => [
                'customer_code' => 'CUS_test123',
            ],
            'next_payment_date' => '2026-04-25',
        ],
    ]);

    $this->postJson(route('webhooks.paystack'), json_decode($payload, true), [
        'X-Paystack-Signature' => signPayload($payload),
    ])->assertSuccessful();

    $family->refresh();
    expect($family->paystack_subscription_code)->toBe('SUB_abc123')
        ->and($family->paystack_subscription_email_token)->toBe('tok_test456')
        ->and($family->subscription_status)->toBe('active');
});

it('handles subscription.not_renew event', function () {
    $family = Family::factory()->create([
        'paystack_subscription_code' => 'SUB_cancel123',
        'subscription_status' => 'active',
    ]);

    $payload = json_encode([
        'event' => 'subscription.not_renew',
        'data' => [
            'subscription_code' => 'SUB_cancel123',
        ],
    ]);

    $this->postJson(route('webhooks.paystack'), json_decode($payload, true), [
        'X-Paystack-Signature' => signPayload($payload),
    ])->assertSuccessful();

    $family->refresh();
    expect($family->subscription_status)->toBe('cancelled');
});
