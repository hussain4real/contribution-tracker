<?php

declare(strict_types=1);

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Jobs\ProcessPaystackWebhook;
use App\Models\AuditEvent;
use App\Models\Contribution;
use App\Models\Expense;
use App\Models\Family;
use App\Models\Payment;
use App\Models\PaystackTransaction;
use App\Models\User;
use App\Services\PaystackWebhookProcessor;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'services.paystack.secret_key' => 'sk_test_secret',
        'services.paystack.base_url' => 'https://api.paystack.co',
        'services.paystack.webhook_secret' => 'whsec_test123',
        'services.paystack.fee_policy' => 'payer_pays',
        'services.paystack.local_fee_basis_points' => 150,
        'services.paystack.local_fee_fixed_kobo' => 10_000,
        'services.paystack.local_fee_fixed_waiver_threshold_kobo' => 250_000,
        'services.paystack.local_fee_cap_kobo' => 200_000,
    ]);
    Queue::fake();
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

it('executes queued Paystack webhooks with a bounded retry schedule', function () {
    $payload = [
        'event' => 'charge.success',
        'data' => ['reference' => 'TXN_JOB'],
    ];
    $processor = typedMock(PaystackWebhookProcessor::class);
    $processor->shouldReceive('process')->once()->with($payload);
    $job = new ProcessPaystackWebhook($payload);

    $job->handle($processor);

    expect($job->backoff())->toBe([10, 30, 120, 300]);
});

it('uniquely identifies failed subscription webhooks by nested subscription code', function () {
    $first = new ProcessPaystackWebhook([
        'event' => 'invoice.payment_failed',
        'data' => ['subscription' => ['subscription_code' => 'SUB_first']],
    ]);
    $same = new ProcessPaystackWebhook([
        'event' => 'invoice.payment_failed',
        'data' => ['subscription' => ['subscription_code' => 'SUB_first']],
    ]);
    $second = new ProcessPaystackWebhook([
        'event' => 'invoice.payment_failed',
        'data' => ['subscription' => ['subscription_code' => 'SUB_second']],
    ]);

    expect($first->uniqueId())->toBe($same->uniqueId())
        ->and($first->uniqueId())->not->toBe($second->uniqueId());
});

it('does not audit Paystack updates that leave ledger state unchanged', function () {
    $family = Family::factory()->create();
    $member = User::factory()->create(['family_id' => $family->id]);
    $transaction = PaystackTransaction::create([
        'reference' => 'TXN_UNCHANGED_STATE',
        'user_id' => $member->id,
        'family_id' => $family->id,
        'type' => TransactionType::Contribution,
        'amount' => 4000,
        'status' => TransactionStatus::Pending,
    ]);
    $auditCount = AuditEvent::query()->count();

    $transaction->forceFill(['failure_reason' => 'Informational note only.'])->save();

    expect(AuditEvent::query()->count())->toBe($auditCount);
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
        'gross_amount_kobo' => 416_244,
        'estimated_fee_kobo' => 16_244,
        'settled_amount_kobo' => 400_000,
        'fee_policy' => 'payer_pays',
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
            'amount' => 416244, // gross kobo
            'fees' => 16244,
            'paid_at' => now()->toDateString(),
            'status' => 'success',
        ],
    ]);

    $this->postJson(route('webhooks.paystack'), decodeJsonObject($payload), [
        'X-Paystack-Signature' => signPayload($payload),
    ])->assertAccepted();

    Queue::assertPushed(ProcessPaystackWebhook::class);
    app(PaystackWebhookProcessor::class)->process(decodeJsonObject($payload));

    $transaction->refresh();
    expect($transaction->status)->toBe(TransactionStatus::Allocated)
        ->and($transaction->payment_batch_id)->not->toBeNull()
        ->and($transaction->actual_fee_kobo)->toBe(16_244)
        ->and($transaction->settled_amount_kobo)->toBe(400_000)
        ->and(Payment::where('contribution_id', $contribution->id)->sum('amount'))->toBe(4000);
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
    ])->assertAccepted();

    app(PaystackWebhookProcessor::class)->process(decodeJsonObject($payload));

    // Should still be success, not re-processed
    $transaction->refresh();
    expect($transaction->status)->toBe(TransactionStatus::Success);
});

it('records a Paystack fee expense when webhook settlement is short', function () {
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
        'reference' => 'TXN_WEBHOOK_SHORTFALL',
        'user_id' => $member->id,
        'family_id' => $family->id,
        'type' => TransactionType::Contribution,
        'amount' => 4000,
        'gross_amount_kobo' => 416_244,
        'estimated_fee_kobo' => 16_244,
        'settled_amount_kobo' => 400_000,
        'fee_policy' => 'payer_pays',
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
            'reference' => 'TXN_WEBHOOK_SHORTFALL',
            'amount' => 416244,
            'fees' => 17244,
            'paid_at' => now()->toDateString(),
            'status' => 'success',
        ],
    ]);

    $this->postJson(route('webhooks.paystack'), decodeJsonObject($payload), [
        'X-Paystack-Signature' => signPayload($payload),
    ])->assertAccepted();

    app(PaystackWebhookProcessor::class)->process(decodeJsonObject($payload));

    $expense = Expense::query()->where('family_id', $family->id)->firstOrFail();

    expect($transaction->refresh()->settled_amount_kobo)->toBe(399_000)
        ->and($transaction->actual_fee_kobo)->toBe(17_244)
        ->and(Payment::where('contribution_id', $contribution->id)->sum('amount'))->toBe(4000)
        ->and($expense->amount)->toBe(10)
        ->and($expense->description)->toBe('Paystack processing fee shortfall for transaction TXN_WEBHOOK_SHORTFALL');
});

it('retries a failed matching charge when a verified success webhook arrives', function () {
    $family = Family::factory()->create();
    $member = User::factory()->create(['family_id' => $family->id]);

    PaystackTransaction::create([
        'reference' => 'TXN_NON_PENDING',
        'user_id' => $member->id,
        'family_id' => $family->id,
        'type' => TransactionType::Contribution,
        'amount' => 4000,
        'gross_amount_kobo' => 400000,
        'status' => TransactionStatus::Failed,
    ]);

    $payload = encodeJsonPayload([
        'event' => 'charge.success',
        'data' => [
            'reference' => 'TXN_NON_PENDING',
            'amount' => 400000,
            'fees' => 0,
            'status' => 'success',
        ],
    ]);

    $this->postJson(route('webhooks.paystack'), decodeJsonObject($payload), [
        'X-Paystack-Signature' => signPayload($payload),
    ])->assertAccepted();

    app(PaystackWebhookProcessor::class)->process(decodeJsonObject($payload));

    expect(PaystackTransaction::query()->where('reference', 'TXN_NON_PENDING')->firstOrFail()->status)
        ->toBe(TransactionStatus::Allocated);
});

it('logs and skips contribution allocation for an unknown webhook reference', function () {
    app(PaystackWebhookProcessor::class)->process([
        'event' => 'charge.success',
        'data' => [
            'reference' => 'TXN_MISSING_MEMBER',
            'amount' => 400000,
            'status' => 'success',
        ],
    ]);

    expect(Payment::query()->count())->toBe(0);
});

it('rejects charge.success events without a reference', function () {
    $payload = encodeJsonPayload([
        'event' => 'charge.success',
        'data' => ['amount' => 400000],
    ]);

    $this->postJson(route('webhooks.paystack'), decodeJsonObject($payload), [
        'X-Paystack-Signature' => signPayload($payload),
    ])->assertAccepted()->assertJson(['message' => 'Accepted']);

    app(PaystackWebhookProcessor::class)->process(decodeJsonObject($payload));
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
    ])->assertAccepted()->assertJson(['message' => 'Accepted']);

    app(PaystackWebhookProcessor::class)->process(decodeJsonObject($payload));
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
        'gross_amount_kobo' => 416_244,
        'estimated_fee_kobo' => 16_244,
        'status' => TransactionStatus::Pending,
    ]);

    $payload = encodeJsonPayload([
        'event' => 'charge.success',
        'data' => [
            'reference' => 'TXN_MISMATCH',
            'amount' => 400000, // Wrong gross amount (expected 416244)
            'fees' => 16244,
            'status' => 'success',
        ],
    ]);

    $this->postJson(route('webhooks.paystack'), decodeJsonObject($payload), [
        'X-Paystack-Signature' => signPayload($payload),
    ])->assertAccepted();

    expect(fn () => app(PaystackWebhookProcessor::class)->process(decodeJsonObject($payload)))
        ->toThrow(RuntimeException::class, 'does not match');

    expect(PaystackTransaction::query()->where('reference', 'TXN_MISMATCH')->firstOrFail()->status)
        ->toBe(TransactionStatus::Failed);
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
    ])->assertAccepted();

    app(PaystackWebhookProcessor::class)->process(decodeJsonObject($payload));

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
    ])->assertAccepted()->assertJson(['message' => 'Accepted']);

    app(PaystackWebhookProcessor::class)->process(decodeJsonObject($payload));
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
    ])->assertAccepted()->assertJson(['message' => 'Accepted']);

    app(PaystackWebhookProcessor::class)->process(decodeJsonObject($payload));
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
    ])->assertAccepted();

    app(PaystackWebhookProcessor::class)->process(decodeJsonObject($payload));

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
    ])->assertAccepted()->assertJson(['message' => 'Accepted']);

    app(PaystackWebhookProcessor::class)->process(decodeJsonObject($payload));
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
    ])->assertAccepted()->assertJson(['message' => 'Accepted']);

    app(PaystackWebhookProcessor::class)->process(decodeJsonObject($payload));

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
    ])->assertAccepted()->assertJson(['message' => 'Accepted']);

    app(PaystackWebhookProcessor::class)->process(decodeJsonObject($payload));
});

it('handles malformed paystack webhook data as missing data', function () {
    $payload = encodeJsonPayload([
        'event' => 'subscription.not_renew',
        'data' => 'not-an-object',
    ]);

    $this->postJson(route('webhooks.paystack'), decodeJsonObject($payload), [
        'X-Paystack-Signature' => signPayload($payload),
    ])->assertAccepted()->assertJson(['message' => 'Accepted']);

    app(PaystackWebhookProcessor::class)->process(decodeJsonObject($payload));
});

it('ignores unknown paystack webhook events', function () {
    $payload = encodeJsonPayload([
        'event' => 'customer.created',
        'data' => [],
    ]);

    $this->postJson(route('webhooks.paystack'), decodeJsonObject($payload), [
        'X-Paystack-Signature' => signPayload($payload),
    ])->assertAccepted()->assertJson(['message' => 'Accepted']);

    app(PaystackWebhookProcessor::class)->process(decodeJsonObject($payload));
});
