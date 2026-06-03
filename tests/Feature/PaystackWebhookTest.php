<?php

declare(strict_types=1);

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Http\Controllers\PaystackWebhookController;
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
    $payload = encodeJsonPayload(['event' => 'charge.success', 'data' => []]);

    $this->postJson(route('webhooks.paystack'), decodeJsonObject($payload), [
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

    $payload = encodeJsonPayload([
        'event' => 'charge.success',
        'data' => [
            'reference' => 'TXN_TEST001',
            'amount' => 400000, // kobo
            'paid_at' => now()->toDateString(),
            'status' => 'success',
        ],
    ]);

    $this->postJson(route('webhooks.paystack'), decodeJsonObject($payload), [
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

    $payload = encodeJsonPayload([
        'event' => 'charge.success',
        'data' => [
            'reference' => 'TXN_DOUBLE',
            'amount' => 400000,
        ],
    ]);

    $this->postJson(route('webhooks.paystack'), decodeJsonObject($payload), [
        'X-Paystack-Signature' => signPayload($payload),
    ])->assertSuccessful();

    // Should still be success, not re-processed
    $transaction->refresh();
    expect($transaction->status)->toBe(TransactionStatus::Success);
});

it('treats non-pending matching charge.success transactions as already processed', function () {
    $family = Family::factory()->create();
    $member = User::factory()->create(['family_id' => $family->id]);

    PaystackTransaction::create([
        'reference' => 'TXN_NON_PENDING',
        'user_id' => $member->id,
        'family_id' => $family->id,
        'type' => TransactionType::Contribution,
        'amount' => 4000,
        'status' => TransactionStatus::Failed,
    ]);

    $payload = encodeJsonPayload([
        'event' => 'charge.success',
        'data' => [
            'reference' => 'TXN_NON_PENDING',
            'amount' => 400000,
        ],
    ]);

    $this->postJson(route('webhooks.paystack'), decodeJsonObject($payload), [
        'X-Paystack-Signature' => signPayload($payload),
    ])
        ->assertSuccessful()
        ->assertJson(['message' => 'Already processed']);
});

it('logs and skips contribution allocation when the webhook member no longer exists', function () {
    $transaction = new PaystackTransaction([
        'reference' => 'TXN_MISSING_MEMBER',
        'user_id' => 999999,
        'family_id' => 999999,
        'type' => TransactionType::Contribution,
        'amount' => 4000,
        'status' => TransactionStatus::Pending,
    ]);

    $controller = app(PaystackWebhookController::class);
    $method = (new ReflectionClass($controller))->getMethod('allocateContributionPayment');
    $method->invoke($controller, $transaction, []);

    expect(User::find(999999))->toBeNull();
});

it('rejects charge.success events without a reference', function () {
    $payload = encodeJsonPayload([
        'event' => 'charge.success',
        'data' => ['amount' => 400000],
    ]);

    $this->postJson(route('webhooks.paystack'), decodeJsonObject($payload), [
        'X-Paystack-Signature' => signPayload($payload),
    ])
        ->assertBadRequest()
        ->assertJson(['message' => 'No reference']);
});

it('ignores charge.success events for unknown references', function () {
    $payload = encodeJsonPayload([
        'event' => 'charge.success',
        'data' => [
            'reference' => 'TXN_UNKNOWN',
            'amount' => 400000,
        ],
    ]);

    $this->postJson(route('webhooks.paystack'), decodeJsonObject($payload), [
        'X-Paystack-Signature' => signPayload($payload),
    ])
        ->assertSuccessful()
        ->assertJson(['message' => 'Unknown reference']);
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

    $payload = encodeJsonPayload([
        'event' => 'charge.success',
        'data' => [
            'reference' => 'TXN_MISMATCH',
            'amount' => 200000, // Wrong amount (2000 Naira in kobo, expected 4000)
        ],
    ]);

    $this->postJson(route('webhooks.paystack'), decodeJsonObject($payload), [
        'X-Paystack-Signature' => signPayload($payload),
    ])->assertStatus(400);
});

it('handles subscription.create event', function () {
    $family = Family::factory()->create([
        'paystack_customer_code' => 'CUS_test123',
    ]);

    $payload = encodeJsonPayload([
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

    $this->postJson(route('webhooks.paystack'), decodeJsonObject($payload), [
        'X-Paystack-Signature' => signPayload($payload),
    ])->assertSuccessful();

    $family->refresh();
    expect($family->paystack_subscription_code)->toBe('SUB_abc123')
        ->and($family->paystack_subscription_email_token)->toBe('tok_test456')
        ->and($family->subscription_status)->toBe('active');
});

it('rejects subscription.create events with missing data', function () {
    $payload = encodeJsonPayload([
        'event' => 'subscription.create',
        'data' => ['customer' => []],
    ]);

    $this->postJson(route('webhooks.paystack'), decodeJsonObject($payload), [
        'X-Paystack-Signature' => signPayload($payload),
    ])
        ->assertBadRequest()
        ->assertJson(['message' => 'Missing subscription data']);
});

it('ignores subscription.create events for unknown customers', function () {
    $payload = encodeJsonPayload([
        'event' => 'subscription.create',
        'data' => [
            'subscription_code' => 'SUB_unknown',
            'customer' => [
                'customer_code' => 'CUS_unknown',
            ],
        ],
    ]);

    $this->postJson(route('webhooks.paystack'), decodeJsonObject($payload), [
        'X-Paystack-Signature' => signPayload($payload),
    ])
        ->assertSuccessful()
        ->assertJson(['message' => 'Family not found']);
});

it('handles subscription.not_renew event', function () {
    $family = Family::factory()->create([
        'paystack_subscription_code' => 'SUB_cancel123',
        'subscription_status' => 'active',
    ]);

    $payload = encodeJsonPayload([
        'event' => 'subscription.not_renew',
        'data' => [
            'subscription_code' => 'SUB_cancel123',
        ],
    ]);

    $this->postJson(route('webhooks.paystack'), decodeJsonObject($payload), [
        'X-Paystack-Signature' => signPayload($payload),
    ])->assertSuccessful();

    $family->refresh();
    expect($family->subscription_status)->toBe('cancelled');
});

it('rejects subscription.not_renew events with missing data', function () {
    $payload = encodeJsonPayload([
        'event' => 'subscription.not_renew',
        'data' => [],
    ]);

    $this->postJson(route('webhooks.paystack'), decodeJsonObject($payload), [
        'X-Paystack-Signature' => signPayload($payload),
    ])
        ->assertBadRequest()
        ->assertJson(['message' => 'Missing subscription data']);
});

it('records invoice payment failures for known subscriptions', function () {
    $family = Family::factory()->create([
        'paystack_subscription_code' => 'SUB_past_due',
        'subscription_status' => 'active',
    ]);

    $payload = encodeJsonPayload([
        'event' => 'invoice.payment_failed',
        'data' => [
            'subscription' => [
                'subscription_code' => 'SUB_past_due',
            ],
        ],
    ]);

    $this->postJson(route('webhooks.paystack'), decodeJsonObject($payload), [
        'X-Paystack-Signature' => signPayload($payload),
    ])
        ->assertSuccessful()
        ->assertJson(['message' => 'Payment failure recorded']);

    expect($family->refresh()->subscription_status)->toBe('past_due');
});

it('rejects invoice payment failures with missing subscription data', function () {
    $payload = encodeJsonPayload([
        'event' => 'invoice.payment_failed',
        'data' => [
            'subscription' => null,
        ],
    ]);

    $this->postJson(route('webhooks.paystack'), decodeJsonObject($payload), [
        'X-Paystack-Signature' => signPayload($payload),
    ])
        ->assertBadRequest()
        ->assertJson(['message' => 'Missing subscription data']);
});

it('handles malformed paystack webhook data as missing data', function () {
    $payload = encodeJsonPayload([
        'event' => 'subscription.not_renew',
        'data' => 'not-an-object',
    ]);

    $this->postJson(route('webhooks.paystack'), decodeJsonObject($payload), [
        'X-Paystack-Signature' => signPayload($payload),
    ])
        ->assertBadRequest()
        ->assertJson(['message' => 'Missing subscription data']);
});

it('ignores unknown paystack webhook events', function () {
    $payload = encodeJsonPayload([
        'event' => 'customer.created',
        'data' => [],
    ]);

    $this->postJson(route('webhooks.paystack'), decodeJsonObject($payload), [
        'X-Paystack-Signature' => signPayload($payload),
    ])
        ->assertSuccessful()
        ->assertJson(['message' => 'Event ignored']);
});
