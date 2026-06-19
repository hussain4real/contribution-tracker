<?php

declare(strict_types=1);

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Contribution;
use App\Models\Expense;
use App\Models\Family;
use App\Models\FamilyCategory;
use App\Models\Payment;
use App\Models\PaystackTransaction;
use App\Models\PlatformPlan;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config([
        'services.paystack.secret_key' => 'sk_test_secret',
        'services.paystack.public_key' => 'pk_test_public',
        'services.paystack.base_url' => 'https://api.paystack.co',
        'services.paystack.fee_policy' => 'payer_pays',
        'services.paystack.local_fee_basis_points' => 150,
        'services.paystack.local_fee_fixed_kobo' => 10_000,
        'services.paystack.local_fee_fixed_waiver_threshold_kobo' => 250_000,
        'services.paystack.local_fee_cap_kobo' => 200_000,
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

it('shows the member monthly amount with the family currency', function () {
    $family = Family::factory()->create(['currency' => 'QAR']);
    $monthlyDues = FamilyCategory::factory()->create([
        'family_id' => $family->id,
        'name' => 'Monthly Dues',
        'monthly_amount' => 100,
    ]);
    $member = User::factory()->member()->create([
        'family_id' => $family->id,
        'category' => null,
        'family_category_id' => $monthlyDues->id,
    ]);

    $this->actingAs($member)
        ->get(route('pay.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Pay/Index')
            ->where('category_amount', 100)
            ->where('formatted_amount', 'QAR 100.00')
        );
});

it('explains when visible bank details are missing the paystack bank code', function () {
    $family = Family::factory()->create([
        'bank_name' => 'Guaranty Trust Bank',
        'account_name' => 'Aminu Hussain',
        'account_number' => '0045502216',
        'bank_code' => null,
    ]);
    $member = User::factory()->create(['family_id' => $family->id]);

    $this->actingAs($member)
        ->get(route('pay.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Pay/Index')
            ->where('has_paystack', false)
            ->where('bank_setup_status', 'incomplete_bank_details')
        );
});

it('explains when bank details have not been saved', function () {
    $family = Family::factory()->create([
        'bank_name' => null,
        'account_name' => null,
        'account_number' => null,
        'bank_code' => null,
    ]);
    $member = User::factory()->create(['family_id' => $family->id]);

    $this->actingAs($member)
        ->get(route('pay.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Pay/Index')
            ->where('has_paystack', false)
            ->where('bank_setup_status', 'missing_bank_details')
        );
});

it('explains when paystack is not configured for the app', function () {
    config(['services.paystack.public_key' => null]);

    $family = Family::factory()->create([
        'bank_name' => 'Guaranty Trust Bank',
        'account_name' => 'Aminu Hussain',
        'account_number' => '0045502216',
        'bank_code' => '058',
    ]);
    $member = User::factory()->create(['family_id' => $family->id]);

    $this->actingAs($member)
        ->get(route('pay.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Pay/Index')
            ->where('has_paystack', false)
            ->where('bank_setup_status', 'missing_paystack_key')
        );
});

it('marks online payments as ready when bank details and paystack key are configured', function () {
    $family = Family::factory()->create([
        'bank_name' => 'Guaranty Trust Bank',
        'account_name' => 'Aminu Hussain',
        'account_number' => '0045502216',
        'bank_code' => '058',
    ]);
    $member = User::factory()->create(['family_id' => $family->id]);

    $this->actingAs($member)
        ->get(route('pay.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Pay/Index')
            ->where('has_paystack', true)
            ->where('bank_setup_status', 'ready')
            ->where('paystack_public_key', 'pk_test_public')
            ->where('paystack_fee.policy', 'payer_pays')
            ->where('paystack_fee.basis_points', 150)
        );
});

it('only shows pending contributions for the current family', function () {
    $legacyFamily = Family::factory()->create();
    $currentFamily = Family::factory()->create([
        'bank_name' => 'Guaranty Trust Bank',
        'bank_code' => '058',
        'account_number' => '0045502216',
    ]);
    $member = User::factory()->member()->create(['family_id' => $legacyFamily->id]);
    $member->ensureFamilyMembership($currentFamily);

    Contribution::factory()->create([
        'family_id' => $legacyFamily->id,
        'user_id' => $member->id,
        'expected_amount' => 4000,
    ]);
    $currentContribution = Contribution::factory()->create([
        'family_id' => $currentFamily->id,
        'user_id' => $member->id,
        'expected_amount' => 4000,
    ]);

    $this->actingAs($member)
        ->get(route('pay.index', ['current_family' => $currentFamily->slug]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Pay/Index')
            ->has('pending_contributions', 1)
            ->where('pending_contributions.0.id', $currentContribution->id)
        );
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

    $this->actingAs($member);

    $response = $this->postJson(route('pay.initiate'), [
        'contribution_ids' => [$contribution->id],
    ]);

    $response
        ->assertSuccessful()
        ->assertJsonStructure(['access_code', 'authorization_url', 'reference'])
        ->assertJson([
            'contribution_amount_kobo' => 400_000,
            'gross_amount_kobo' => 416_244,
            'estimated_fee_kobo' => 16_244,
        ]);

    $responsePayload = $response->json();

    if (! is_array($responsePayload)) {
        throw new RuntimeException('Expected Paystack initiation response to be a JSON object.');
    }

    $reference = stringValue($responsePayload, 'reference');

    Http::assertSent(function (Request $request) use ($family, $member, $reference): bool {
        $data = $request->data();
        $metadata = $data['metadata'] ?? [];

        return str_ends_with($request->url(), '/transaction/initialize')
            && stringValue($data, 'email') === $member->email
            && intValue($data, 'amount') === 416_244
            && stringValue($data, 'reference') === $reference
            && stringValue($data, 'subaccount') === $family->paystack_subaccount_code
            && stringValue($data, 'bearer') === 'subaccount'
            && is_array($metadata)
            && intValue($metadata, 'contribution_amount_kobo') === 400_000
            && intValue($metadata, 'gross_amount_kobo') === 416_244
            && intValue($metadata, 'estimated_fee_kobo') === 16_244
            && stringValue($metadata, 'fee_policy') === 'payer_pays';
    });

    $transaction = PaystackTransaction::where('reference', $reference)->firstOrFail();

    expect($transaction->amount)->toBe(4000)
        ->and($transaction->gross_amount_kobo)->toBe(416_244)
        ->and($transaction->estimated_fee_kobo)->toBe(16_244)
        ->and($transaction->settled_amount_kobo)->toBe(400_000)
        ->and($transaction->fee_policy)->toBe('payer_pays');
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

    expect(PaystackTransaction::query()->firstOrFail()->status)->toBe(TransactionStatus::Failed);
});

it('marks the transaction failed when paystack initialization returns malformed data', function () {
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => null,
        ]),
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

    expect(PaystackTransaction::query()->firstOrFail()->status)->toBe(TransactionStatus::Failed);
});

it('marks the transaction failed when paystack initialization omits required checkout fields', function () {
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/missing-access-code',
            ],
        ]),
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

    expect(PaystackTransaction::query()->firstOrFail()->status)->toBe(TransactionStatus::Failed);
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

it('rejects initiation with contribution ids from another family', function () {
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/test',
                'access_code' => 'access_test',
                'reference' => 'TXN_TEST123',
            ],
        ]),
    ]);

    $legacyFamily = Family::factory()->create();
    $currentFamily = Family::factory()->create([
        'bank_name' => 'Kuda Bank',
        'bank_code' => '090267',
        'account_number' => '1234567890',
    ]);
    $member = User::factory()->member()->create(['family_id' => $legacyFamily->id]);
    $member->ensureFamilyMembership($currentFamily);
    $legacyContribution = Contribution::factory()->create([
        'family_id' => $legacyFamily->id,
        'user_id' => $member->id,
        'expected_amount' => 4000,
    ]);

    $this->actingAs($member)
        ->postJson(route('pay.initiate', ['current_family' => $currentFamily->slug]), [
            'contribution_ids' => [$legacyContribution->id],
        ])
        ->assertUnprocessable()
        ->assertJson(['message' => 'No unpaid contributions selected.']);

    Http::assertNothingSent();
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
                'amount' => 416244,
                'fees' => 16244,
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

    $this->actingAs($member)
        ->get(route('pay.callback', ['reference' => 'TXN_SUCCESS']))
        ->assertRedirect(route('pay.index'))
        ->assertSessionHas('success', 'Payment successful! Your contributions have been updated.');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::Success)
        ->and($transaction->actual_fee_kobo)->toBe(16_244)
        ->and($transaction->settled_amount_kobo)->toBe(400_000)
        ->and(Expense::query()->where('family_id', $family->id)->exists())->toBeFalse()
        ->and(Payment::where('contribution_id', $contribution->id)->sum('amount'))->toBe(4000);
});

it('records only the settlement shortfall as a Paystack fee expense', function () {
    Http::fake([
        'api.paystack.co/transaction/verify/TXN_SHORTFALL' => Http::response([
            'status' => true,
            'message' => 'Verification successful',
            'data' => [
                'status' => 'success',
                'amount' => 416244,
                'fees' => 17244,
                'reference' => 'TXN_SHORTFALL',
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
        'reference' => 'TXN_SHORTFALL',
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

    $this->actingAs($member)
        ->get(route('pay.callback', ['reference' => 'TXN_SHORTFALL']))
        ->assertRedirect(route('pay.index'))
        ->assertSessionHas('success', 'Payment successful! Your contributions have been updated.');

    $expense = Expense::query()->where('family_id', $family->id)->firstOrFail();

    expect($transaction->refresh()->settled_amount_kobo)->toBe(399_000)
        ->and($transaction->actual_fee_kobo)->toBe(17_244)
        ->and(Payment::where('contribution_id', $contribution->id)->sum('amount'))->toBe(4000)
        ->and($expense->amount)->toBe(10)
        ->and($expense->description)->toBe('Paystack processing fee shortfall for transaction TXN_SHORTFALL');
});

it('rejects successful callbacks when the verified gross amount does not match', function () {
    Http::fake([
        'api.paystack.co/transaction/verify/TXN_BAD_AMOUNT' => Http::response([
            'status' => true,
            'message' => 'Verification successful',
            'data' => [
                'status' => 'success',
                'amount' => 400000,
                'fees' => 16244,
                'reference' => 'TXN_BAD_AMOUNT',
                'paid_at' => '2026-05-11',
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
        'reference' => 'TXN_BAD_AMOUNT',
        'user_id' => $member->id,
        'family_id' => $family->id,
        'type' => TransactionType::Contribution,
        'amount' => 4000,
        'gross_amount_kobo' => 416_244,
        'estimated_fee_kobo' => 16_244,
        'status' => TransactionStatus::Pending,
        'metadata' => [
            'contribution_ids' => [$contribution->id],
            'target_year' => $contribution->year,
            'target_month' => $contribution->month,
        ],
    ]);

    $this->actingAs($member)
        ->get(route('pay.callback', ['reference' => 'TXN_BAD_AMOUNT']))
        ->assertRedirect(route('pay.index'))
        ->assertSessionHas('error', 'Payment amount could not be verified. Please contact support if you were charged.');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::Failed)
        ->and(Payment::where('contribution_id', $contribution->id)->exists())->toBeFalse();
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
