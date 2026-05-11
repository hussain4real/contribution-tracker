<?php

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\Payment;
use App\Models\PaystackTransaction;
use App\Models\PlatformPlan;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.paystack.secret_key' => 'sk_test_secret',
        'services.paystack.public_key' => 'pk_test_public',
        'services.paystack.base_url' => 'https://api.paystack.co',
    ]);

    // Create a free plan so the subscription middleware doesn't block
    PlatformPlan::create([
        'name' => 'Free',
        'slug' => 'free',
        'price' => 0,
        'max_members' => 5,
        'features' => ['basic_contributions', 'manual_payments', 'online_payments'],
        'is_active' => true,
        'sort_order' => 0,
    ]);
});

it('shows the pay page for authenticated member', function () {
    $family = Family::factory()->create([
        'bank_name' => 'Kuda Bank',
        'bank_code' => '090267',
        'account_number' => '1234567890',
    ]);
    $member = User::factory()->create(['family_id' => $family->id]);

    $this->actingAs($member)
        ->get(route('pay.index'))
        ->assertSuccessful();
});

it('redirects guests from pay page', function () {
    $this->get(route('pay.index'))
        ->assertRedirect();
});

it('initiates a payment for selected contributions', function () {
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'message' => 'Authorization URL created',
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/test',
                'access_code' => 'access_test',
                'reference' => 'TXN_TEST123',
            ],
        ]),
    ]);

    $family = Family::factory()->create([
        'paystack_subaccount_code' => 'ACCT_test',
        'bank_name' => 'Kuda Bank',
        'bank_code' => '090267',
        'account_number' => '1234567890',
    ]);
    $member = User::factory()->create(['family_id' => $family->id]);

    $contribution = Contribution::factory()->create([
        'family_id' => $family->id,
        'user_id' => $member->id,
        'expected_amount' => 4000,
    ]);

    $this->actingAs($member)
        ->postJson(route('pay.initiate'), [
            'contribution_ids' => [$contribution->id],
        ])
        ->assertSuccessful()
        ->assertJsonStructure(['access_code', 'authorization_url', 'reference']);
});

it('marks the transaction failed when paystack initialization fails', function () {
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response(['message' => 'Gateway down'], 500),
    ]);

    $family = Family::factory()->create([
        'bank_name' => 'Kuda Bank',
        'bank_code' => '090267',
        'account_number' => '1234567890',
    ]);
    $member = User::factory()->create(['family_id' => $family->id]);
    $contribution = Contribution::factory()->create([
        'family_id' => $family->id,
        'user_id' => $member->id,
        'expected_amount' => 4000,
    ]);

    $this->actingAs($member)
        ->postJson(route('pay.initiate'), [
            'contribution_ids' => [$contribution->id],
        ])
        ->assertServerError()
        ->assertJson(['message' => 'Failed to initialize payment. Please try again.']);

    expect(PaystackTransaction::first()->status)->toBe(TransactionStatus::Failed);
});

it('fails to initiate without bank details', function () {
    $family = Family::factory()->create([
        'bank_name' => null,
        'bank_code' => null,
        'account_number' => null,
    ]);
    $member = User::factory()->create(['family_id' => $family->id]);

    $contribution = Contribution::factory()->create([
        'family_id' => $family->id,
        'user_id' => $member->id,
        'expected_amount' => 4000,
    ]);

    $this->actingAs($member)
        ->postJson(route('pay.initiate'), [
            'contribution_ids' => [$contribution->id],
        ])
        ->assertUnprocessable()
        ->assertJsonFragment(['message' => 'Online payments are not set up for your family. Ask your admin to configure bank details.']);
});

it('validates contribution_ids are required', function () {
    $family = Family::factory()->create([
        'bank_name' => 'Kuda Bank',
        'bank_code' => '090267',
        'account_number' => '1234567890',
    ]);
    $member = User::factory()->create(['family_id' => $family->id]);

    $this->actingAs($member)
        ->postJson(route('pay.initiate'), [
            'contribution_ids' => [],
        ])
        ->assertUnprocessable();
});

it('rejects initiation when no unpaid contributions are selected', function () {
    $family = Family::factory()->create([
        'bank_name' => 'Kuda Bank',
        'bank_code' => '090267',
        'account_number' => '1234567890',
    ]);
    $member = User::factory()->create(['family_id' => $family->id]);
    $otherMember = User::factory()->create(['family_id' => $family->id]);
    $otherContribution = Contribution::factory()->create([
        'family_id' => $family->id,
        'user_id' => $otherMember->id,
        'expected_amount' => 4000,
    ]);

    $this->actingAs($member)
        ->postJson(route('pay.initiate'), [
            'contribution_ids' => [$otherContribution->id],
        ])
        ->assertUnprocessable()
        ->assertJson(['message' => 'No unpaid contributions selected.']);
});

it('rejects initiation when selected contributions have no balance', function () {
    $family = Family::factory()->create([
        'bank_name' => 'Kuda Bank',
        'bank_code' => '090267',
        'account_number' => '1234567890',
    ]);
    $member = User::factory()->create(['family_id' => $family->id]);
    $contribution = Contribution::factory()->create([
        'family_id' => $family->id,
        'user_id' => $member->id,
        'expected_amount' => 0,
    ]);

    $this->actingAs($member)
        ->postJson(route('pay.initiate'), [
            'contribution_ids' => [$contribution->id],
        ])
        ->assertUnprocessable()
        ->assertJson(['message' => 'No unpaid contributions selected.']);
});

it('rejects initiation when selected contributions become paid during calculation', function () {
    $family = Family::factory()->create([
        'bank_name' => 'Kuda Bank',
        'bank_code' => '090267',
        'account_number' => '1234567890',
    ]);
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create([
        'expected_amount' => 4000,
    ]);

    $simulateRace = true;
    Contribution::retrieved(function (Contribution $retrieved) use (&$simulateRace, $contribution, $member): void {
        if (! $simulateRace || $retrieved->id !== $contribution->id) {
            return;
        }

        $simulateRace = false;
        Payment::factory()
            ->forContribution($retrieved)
            ->recordedBy($member)
            ->create(['amount' => 4000]);
    });

    $this->actingAs($member)
        ->postJson(route('pay.initiate'), [
            'contribution_ids' => [$contribution->id],
        ])
        ->assertUnprocessable()
        ->assertJson(['message' => 'Selected contributions are already paid.']);
});

it('redirects callbacks without a reference', function () {
    $member = User::factory()->create();

    $this->actingAs($member)
        ->get(route('pay.callback'))
        ->assertRedirect(route('pay.index'))
        ->assertSessionHas('error', 'Invalid payment reference.');
});

it('redirects callbacks for unknown transactions', function () {
    $member = User::factory()->create();

    $this->actingAs($member)
        ->get(route('pay.callback', ['reference' => 'TXN_MISSING']))
        ->assertRedirect(route('pay.index'))
        ->assertSessionHas('error', 'Transaction not found.');
});

it('allocates a successful callback payment', function () {
    Http::fake([
        'api.paystack.co/transaction/verify/TXN_SUCCESS' => Http::response([
            'status' => true,
            'message' => 'Verification successful',
            'data' => [
                'status' => 'success',
                'amount' => 400000,
                'reference' => 'TXN_SUCCESS',
                'channel' => 'card',
                'currency' => 'NGN',
                'paid_at' => '2026-05-11',
                'metadata' => [],
            ],
        ]),
    ]);

    $family = Family::factory()->create([
        'bank_name' => 'Kuda Bank',
        'bank_code' => '090267',
        'account_number' => '1234567890',
    ]);
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create(['expected_amount' => 4000]);
    $transaction = PaystackTransaction::create([
        'reference' => 'TXN_SUCCESS',
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

    $this->actingAs($member)
        ->get(route('pay.callback', ['reference' => 'TXN_SUCCESS']))
        ->assertRedirect(route('pay.index'))
        ->assertSessionHas('success', 'Payment successful! Your contributions have been updated.');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::Success)
        ->and(Payment::where('contribution_id', $contribution->id)->sum('amount'))->toBe(4000);
});

it('handles successful callbacks that were already processed', function () {
    Http::fake([
        'api.paystack.co/transaction/verify/TXN_ALREADY_SUCCESS' => Http::response([
            'status' => true,
            'message' => 'Verification successful',
            'data' => [
                'status' => 'success',
                'amount' => 400000,
                'reference' => 'TXN_ALREADY_SUCCESS',
                'channel' => 'card',
                'currency' => 'NGN',
                'metadata' => [],
            ],
        ]),
    ]);

    $member = User::factory()->member()->employed()->create();
    PaystackTransaction::create([
        'reference' => 'TXN_ALREADY_SUCCESS',
        'user_id' => $member->id,
        'family_id' => $member->family_id,
        'type' => TransactionType::Contribution,
        'amount' => 4000,
        'status' => TransactionStatus::Success,
    ]);

    $this->actingAs($member)
        ->get(route('pay.callback', ['reference' => 'TXN_ALREADY_SUCCESS']))
        ->assertRedirect(route('pay.index'))
        ->assertSessionHas('success', 'Payment successful! Your contributions have been updated.');
});

it('returns already confirmed when a non-success callback arrives for a successful transaction', function () {
    Http::fake([
        'api.paystack.co/transaction/verify/TXN_CONFIRMED' => Http::response([
            'status' => true,
            'message' => 'Verification successful',
            'data' => [
                'status' => 'failed',
                'amount' => 400000,
                'reference' => 'TXN_CONFIRMED',
                'channel' => 'card',
                'currency' => 'NGN',
                'metadata' => [],
            ],
        ]),
    ]);

    $member = User::factory()->member()->employed()->create();
    PaystackTransaction::create([
        'reference' => 'TXN_CONFIRMED',
        'user_id' => $member->id,
        'family_id' => $member->family_id,
        'type' => TransactionType::Contribution,
        'amount' => 4000,
        'status' => TransactionStatus::Success,
    ]);

    $this->actingAs($member)
        ->get(route('pay.callback', ['reference' => 'TXN_CONFIRMED']))
        ->assertRedirect(route('pay.index'))
        ->assertSessionHas('success', 'Payment already confirmed.');
});

it('shows an error for failed callback verification statuses', function () {
    Http::fake([
        'api.paystack.co/transaction/verify/TXN_FAILED' => Http::response([
            'status' => true,
            'message' => 'Verification successful',
            'data' => [
                'status' => 'failed',
                'amount' => 400000,
                'reference' => 'TXN_FAILED',
                'channel' => 'card',
                'currency' => 'NGN',
                'metadata' => [],
            ],
        ]),
    ]);

    $member = User::factory()->member()->employed()->create();
    PaystackTransaction::create([
        'reference' => 'TXN_FAILED',
        'user_id' => $member->id,
        'family_id' => $member->family_id,
        'type' => TransactionType::Contribution,
        'amount' => 4000,
        'status' => TransactionStatus::Pending,
    ]);

    $this->actingAs($member)
        ->get(route('pay.callback', ['reference' => 'TXN_FAILED']))
        ->assertRedirect(route('pay.index'))
        ->assertSessionHas('error', 'Payment was not successful. Please try again.');
});

it('shows pending confirmation when callback verification throws', function () {
    Http::fake([
        'api.paystack.co/transaction/verify/TXN_ERROR' => Http::response(['message' => 'Gateway down'], 500),
    ]);

    $member = User::factory()->member()->employed()->create();
    PaystackTransaction::create([
        'reference' => 'TXN_ERROR',
        'user_id' => $member->id,
        'family_id' => $member->family_id,
        'type' => TransactionType::Contribution,
        'amount' => 4000,
        'status' => TransactionStatus::Pending,
    ]);

    $this->actingAs($member)
        ->get(route('pay.callback', ['reference' => 'TXN_ERROR']))
        ->assertRedirect(route('pay.index'))
        ->assertSessionHas('error', 'Could not verify payment. Your payment will be confirmed shortly.');
});
