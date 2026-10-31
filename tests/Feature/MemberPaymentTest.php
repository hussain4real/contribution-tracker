<?php

declare(strict_types=1);

use App\Actions\ReversePaymentBatch;
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
use App\Services\PaystackContributionSettlementService;
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

it('does not offer or initialize online payment when a required key is missing', function (string $missingKey) {
    config([$missingKey => null]);
    Http::preventStrayRequests();
    Http::fake(['api.paystack.co/transaction/initialize' => Http::response()]);
    $family = Family::factory()->create(['bank_code' => '058', 'account_number' => '0045502216']);
    $member = User::factory()->member()->create(['family_id' => $family->id]);
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create(['expected_amount' => 4000]);

    $this->actingAs($member)->get(route('pay.index'))
        ->assertOk()->assertDontSee('sk_test_secret')->assertInertia(fn (Assert $page) => $page
        ->where('has_paystack', false)
        ->where('bank_setup_status', 'missing_paystack_key')
        ->missing('paystack_secret_key'));

    $this->postJson(route('pay.initiate'), [
        'contribution_ids' => [$contribution->id],
        'expected_contribution_amount_kobo' => 400_000,
        'expected_gross_amount_kobo' => 416_244,
    ])->assertUnprocessable()->assertJsonPath('message', 'Online payments are currently unavailable.');

    expect(PaystackTransaction::count())->toBe(0);
    Http::assertNothingSent();
})->with(['secret key' => 'services.paystack.secret_key', 'public key' => 'services.paystack.public_key']);

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

it('does not initiate checkout when any selected contribution exceeds the advance limit', function () {
    $this->travelTo(now()->setDate(2026, 10, 31));
    Http::fake();
    $family = Family::factory()->create([
        'paystack_subaccount_code' => 'ACCT_test',
        'bank_name' => 'Kuda Bank',
        'bank_code' => '090267',
        'account_number' => '1234567890',
    ]);
    $member = User::factory()->create(['family_id' => $family->id]);
    $allowed = Contribution::factory()->create([
        'family_id' => $family->id, 'user_id' => $member->id,
        'year' => 2027, 'month' => 4, 'expected_amount' => 4000,
    ]);
    $tooFar = Contribution::factory()->create([
        'family_id' => $family->id, 'user_id' => $member->id,
        'year' => 2027, 'month' => 5, 'expected_amount' => 4000,
    ]);

    $this->actingAs($member)
        ->postJson(route('pay.initiate'), ['expected_contribution_amount_kobo' => 400_000, 'expected_gross_amount_kobo' => 416_244, 'contribution_ids' => [$allowed->id, $tooFar->id]])
        ->assertUnprocessable()
        ->assertJson(['message' => 'Advance payments are limited to 6 months ahead.']);

    expect(PaystackTransaction::count())->toBe(0);
    Http::assertNothingSent();
});

it('marks periods beyond the six-month limit as unavailable for online payment at month end', function () {
    $this->travelTo(now()->setDate(2026, 10, 31));
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $allowed = Contribution::factory()->forUser($member)->forMonth(2027, 4)->create(['expected_amount' => 4000]);
    $tooFar = Contribution::factory()->forUser($member)->forMonth(2027, 5)->create(['expected_amount' => 4000]);

    $this->actingAs($member)->get(route('pay.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('pending_contributions.0.id', $allowed->id)
            ->where('pending_contributions.0.can_pay_online', true)
            ->where('pending_contributions.1.id', $tooFar->id)
            ->where('pending_contributions.1.can_pay_online', false));
});

it('initiates a payment for selected contributions', function () {
    $this->travelTo(now()->setDate(2026, 10, 31));
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
        'year' => 2027,
        'month' => 4,
        'expected_amount' => 4000,
    ]);

    $this->actingAs($member);

    $response = $this->postJson(route('pay.initiate'), [
        'expected_contribution_amount_kobo' => 400_000, 'expected_gross_amount_kobo' => 416_244,
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

it('applies an online payment to the oldest unpaid period even when a newer period sets the amount', function () {
    $this->travelTo(now()->setDate(2026, 10, 15));
    Http::fake(function (Request $request) {
        if (str_ends_with($request->url(), '/transaction/initialize')) {
            return Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/test', 'access_code' => 'access_test']]);
        }

        return Http::response(['status' => true, 'data' => [
            'status' => 'success', 'amount' => 416_244, 'fees' => 16_244,
            'reference' => basename($request->url()), 'paid_at' => now()->toIso8601String(),
        ]]);
    });
    $family = Family::factory()->create([
        'bank_name' => 'Kuda Bank', 'bank_code' => '090267', 'account_number' => '1234567890',
    ]);
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $oldest = Contribution::factory()->forUser($member)->forMonth(2026, 9)->create(['expected_amount' => 4000]);
    $selected = Contribution::factory()->forUser($member)->forMonth(2026, 10)->create(['expected_amount' => 4000]);

    $this->actingAs($member)->postJson(route('pay.initiate'), [
        'contribution_ids' => [$selected->id],
        'expected_contribution_amount_kobo' => 400_000,
        'expected_gross_amount_kobo' => 416_244,
    ])->assertSuccessful();
    $reference = PaystackTransaction::query()->sole()->reference;
    $this->actingAs($member)->get(route('pay.callback', ['reference' => $reference]))
        ->assertRedirect(route('contributions.my'))
        ->assertInertiaFlash('payment_result', ['kind' => 'success', 'message' => 'Payment successful! Your contributions have been updated.']);

    expect(Payment::where('contribution_id', $oldest->id)->sum('amount'))->toBe(4000)
        ->and(Payment::where('contribution_id', $selected->id)->sum('amount'))->toBe(0);
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
            'expected_contribution_amount_kobo' => 400_000, 'expected_gross_amount_kobo' => 416_244,
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
            'expected_contribution_amount_kobo' => 400_000, 'expected_gross_amount_kobo' => 416_244,
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
            'expected_contribution_amount_kobo' => 400_000, 'expected_gross_amount_kobo' => 416_244,
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
            'expected_contribution_amount_kobo' => 400_000, 'expected_gross_amount_kobo' => 416_244,
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
            'expected_contribution_amount_kobo' => 400_000, 'expected_gross_amount_kobo' => 416_244,
            'contribution_ids' => [],
        ])
        ->assertUnprocessable();
});

it('requires the amounts shown before checkout', function () {
    $member = User::factory()->member()->employed()->create();
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create(['expected_amount' => 4000]);

    $this->actingAs($member)->postJson(route('pay.initiate'), ['contribution_ids' => [$contribution->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['expected_contribution_amount_kobo', 'expected_gross_amount_kobo']);
});

it('does not open checkout when the displayed balance or fee is stale', function (string $case) {
    Http::fake();
    $family = Family::factory()->create([
        'bank_name' => 'Kuda Bank', 'bank_code' => '090267', 'account_number' => '1234567890',
    ]);
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create(['expected_amount' => 4000]);
    if ($case === 'balance') {
        Payment::factory()->forContribution($contribution)->recordedBy($member)->create(['amount' => 1000]);
    } else {
        config(['services.paystack.local_fee_basis_points' => 200]);
    }

    $this->actingAs($member)->postJson(route('pay.initiate'), [
        'contribution_ids' => [$contribution->id],
        'expected_contribution_amount_kobo' => 400_000,
        'expected_gross_amount_kobo' => 416_244,
    ])->assertUnprocessable()->assertJson(['message' => 'Your payment amount has changed. Refresh and review it before trying again.']);

    expect(PaystackTransaction::count())->toBe(0);
    Http::assertNothingSent();
})->with(['balance', 'fee']);

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
            'expected_contribution_amount_kobo' => 400_000, 'expected_gross_amount_kobo' => 416_244,
            'contribution_ids' => [$otherContribution->id],
        ])
        ->assertUnprocessable()
        ->assertJson(['message' => 'No unpaid contributions selected.']);
});

it('does not initiate checkout for a mixed selection containing an unavailable contribution', function (string $case) {
    Http::fake();
    $family = Family::factory()->create([
        'bank_name' => 'Kuda Bank', 'bank_code' => '090267', 'account_number' => '1234567890',
    ]);
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $valid = Contribution::factory()->forUser($member)->currentMonth()->create(['expected_amount' => 4000]);
    $otherFamily = $case === 'family' ? Family::factory()->create() : $family;
    $owner = $case === 'member'
        ? User::factory()->member()->employed()->create(['family_id' => $family->id])
        : $member;
    $unavailable = Contribution::factory()->create([
        'family_id' => $otherFamily->id, 'user_id' => $owner->id,
        'year' => now()->copy()->startOfMonth()->addMonth()->year,
        'month' => now()->copy()->startOfMonth()->addMonth()->month,
        'expected_amount' => $case === 'paid' ? 0 : 4000,
    ]);

    $this->actingAs($member)->postJson(route('pay.initiate'), ['expected_contribution_amount_kobo' => 400_000, 'expected_gross_amount_kobo' => 416_244, 'contribution_ids' => [$valid->id, $unavailable->id]])
        ->assertUnprocessable()
        ->assertJson(['message' => 'Some selected contributions are no longer available. Refresh and try again.']);

    expect(PaystackTransaction::count())->toBe(0);
    Http::assertNothingSent();
})->with(['member', 'family', 'paid']);

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
            'expected_contribution_amount_kobo' => 400_000, 'expected_gross_amount_kobo' => 416_244,
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
            'expected_contribution_amount_kobo' => 400_000, 'expected_gross_amount_kobo' => 416_244,
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
            'expected_contribution_amount_kobo' => 400_000, 'expected_gross_amount_kobo' => 416_244,
            'contribution_ids' => [$contribution->id],
        ])
        ->assertUnprocessable()
        ->assertJson(['message' => 'Selected contributions are already paid.']);
});

it('does not initiate a reduced checkout when one of several selections becomes paid during calculation', function () {
    Http::fake();
    $family = Family::factory()->create([
        'bank_name' => 'Kuda Bank', 'bank_code' => '090267', 'account_number' => '1234567890',
    ]);
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $first = Contribution::factory()->forUser($member)->currentMonth()->create(['expected_amount' => 4000]);
    $nextMonth = now()->startOfMonth()->addMonth();
    $second = Contribution::factory()->forUser($member)->forMonth($nextMonth->year, $nextMonth->month)->create(['expected_amount' => 4000]);
    $simulateRace = true;
    Contribution::retrieved(function (Contribution $retrieved) use (&$simulateRace, $second, $member): void {
        if ($simulateRace && $retrieved->id === $second->id) {
            $simulateRace = false;
            Payment::factory()->forContribution($retrieved)->recordedBy($member)->create(['amount' => 4000]);
        }
    });

    $this->actingAs($member)->postJson(route('pay.initiate'), ['expected_contribution_amount_kobo' => 400_000, 'expected_gross_amount_kobo' => 416_244, 'contribution_ids' => [$first->id, $second->id]])
        ->assertUnprocessable()
        ->assertJson(['message' => 'Some selected contributions are no longer available. Refresh and try again.']);

    expect(PaystackTransaction::count())->toBe(0);
    Http::assertNothingSent();
});

it('redirects callbacks without a reference', function () {
    $member = User::factory()->create();

    $this->actingAs($member)
        ->get(route('pay.callback'))
        ->assertRedirect(route('contributions.my'))
        ->assertInertiaFlash('payment_result', ['kind' => 'error', 'message' => 'Invalid payment reference.']);
});

it('redirects callbacks for unknown transactions', function () {
    $member = User::factory()->create();

    $this->actingAs($member)
        ->get(route('pay.callback', ['reference' => 'TXN_MISSING']))
        ->assertRedirect(route('contributions.my'))
        ->assertInertiaFlash('payment_result', ['kind' => 'error', 'message' => 'Transaction not found.']);
});

it('does not verify another member, family, or payment type through the contribution callback', function (string $scope) {
    Http::fake();
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $transactionFamily = $scope === 'family' ? Family::factory()->create() : $family;
    if ($scope === 'family') {
        $member->ensureFamilyMembership($transactionFamily);
    }
    $owner = $scope === 'member'
        ? User::factory()->member()->employed()->create(['family_id' => $family->id])
        : $member;
    $transaction = PaystackTransaction::create([
        'reference' => 'TXN_OTHER_SCOPE', 'user_id' => $owner->id, 'family_id' => $transactionFamily->id,
        'type' => $scope === 'type' ? TransactionType::Subscription : TransactionType::Contribution,
        'amount' => 4000, 'status' => TransactionStatus::Pending,
    ]);

    $this->actingAs($member)->get(route('pay.callback', ['current_family' => $family->slug, 'reference' => $transaction->reference]))
        ->assertRedirect(route('contributions.my', ['current_family' => $family->slug]))
        ->assertInertiaFlash('payment_result', ['kind' => 'error', 'message' => 'Transaction not found.']);
    Http::assertNothingSent();
    expect($transaction->refresh()->status)->toBe(TransactionStatus::Pending)
        ->and($transaction->payment_batch_id)->toBeNull();
})->with(['member', 'family', 'type']);

it('requires authentication before verifying a payment callback', function () {
    Http::preventStrayRequests();
    Http::fake(['api.paystack.co/transaction/verify/*' => Http::response()]);

    $this->get(route('pay.callback', ['current_family' => 'test-family', 'reference' => 'TXN_GUEST']))
        ->assertRedirect(route('login'));

    Http::assertNothingSent();
});

it('settles an existing payment when checkout is no longer available to the family', function (array $features, string $subscriptionStatus) {
    Http::preventStrayRequests();
    Http::fake(['api.paystack.co/transaction/verify/TXN_PLAN_CHANGED' => Http::response([
        'status' => true,
        'data' => ['status' => 'success', 'amount' => 416244, 'fees' => 16244, 'reference' => 'TXN_PLAN_CHANGED', 'channel' => 'card', 'currency' => 'NGN', 'paid_at' => '2026-05-11', 'metadata' => []],
    ])]);
    $plan = PlatformPlan::create(['name' => 'Paid', 'slug' => 'paid', 'price' => 1000, 'max_members' => 5, 'features' => $features, 'is_active' => true, 'sort_order' => 1]);
    $family = Family::factory()->create(['platform_plan_id' => $plan->id, 'subscription_status' => $subscriptionStatus, 'bank_code' => '058', 'account_number' => '1234567890']);
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create(['expected_amount' => 4000]);
    $transaction = PaystackTransaction::create([
        'reference' => 'TXN_PLAN_CHANGED', 'user_id' => $member->id, 'family_id' => $family->id,
        'type' => TransactionType::Contribution, 'amount' => 4000, 'gross_amount_kobo' => 416_244,
        'estimated_fee_kobo' => 16_244, 'settled_amount_kobo' => 400_000, 'fee_policy' => 'payer_pays',
        'status' => TransactionStatus::Pending,
        'metadata' => ['contribution_ids' => [$contribution->id], 'target_year' => $contribution->year, 'target_month' => $contribution->month],
    ]);

    $this->actingAs($member)->postJson(route('pay.initiate'), [])->assertForbidden();
    $this->get(route('pay.callback', ['reference' => $transaction->reference]))
        ->assertRedirect(route('contributions.my'))
        ->assertInertiaFlash('payment_result', ['kind' => 'success', 'message' => 'Payment successful! Your contributions have been updated.']);

    expect($transaction->refresh()->status)->toBe(TransactionStatus::Allocated)
        ->and(Payment::where('contribution_id', $contribution->id)->sum('amount'))->toBe(4000);
    $this->get(route('contributions.my'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('receipts.data.0.id', $transaction->payment_batch_id));
    $this->get(route('pay.callback', ['reference' => $transaction->reference]))
        ->assertRedirect(route('contributions.my'))->assertInertiaFlash('payment_result', ['kind' => 'success', 'message' => 'Payment already confirmed.']);
    expect(Payment::where('contribution_id', $contribution->id)->count())->toBe(1);
})->with([
    'feature removed' => [[], 'active'],
    'subscription cancelled' => [['online_payments'], 'cancelled'],
    'subscription past due' => [['online_payments'], 'past_due'],
]);

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
        ->assertRedirect(route('contributions.my'))
        ->assertInertiaFlash('payment_result', ['kind' => 'success', 'message' => 'Payment successful! Your contributions have been updated.']);

    expect($transaction->refresh()->status)->toBe(TransactionStatus::Allocated)
        ->and($transaction->payment_batch_id)->not->toBeNull()
        ->and($transaction->actual_fee_kobo)->toBe(16_244)
        ->and($transaction->settled_amount_kobo)->toBe(400_000)
        ->and(Expense::query()->where('family_id', $family->id)->exists())->toBeFalse()
        ->and(Payment::where('contribution_id', $contribution->id)->sum('amount'))->toBe(4000);

    $this->actingAs($member)
        ->get(route('pay.callback', ['reference' => 'TXN_SUCCESS']))
        ->assertRedirect(route('contributions.my'))
        ->assertInertiaFlash('payment_result', ['kind' => 'success', 'message' => 'Payment already confirmed.']);
    expect(Payment::where('contribution_id', $contribution->id)->count())->toBe(1);

    app(ReversePaymentBatch::class)->handle($transaction->paymentBatch()->firstOrFail(), $member, 'Correction');
    $this->actingAs($member)
        ->get(route('pay.callback', ['reference' => 'TXN_SUCCESS']))
        ->assertRedirect(route('contributions.my'))
        ->assertInertiaFlash('payment_result', ['kind' => 'error', 'message' => 'This payment was reversed and no longer counts toward your contributions. Contact your family admin if you need help.']);
    expect($transaction->refresh()->status)->toBe(TransactionStatus::Reversed)
        ->and(Payment::where('contribution_id', $contribution->id)->count())->toBe(1);
    Http::assertSentCount(1);
});

it('reports when a verified callback cannot yet be posted to the ledger', function () {
    Http::fake([
        'api.paystack.co/transaction/verify/TXN_UNPOSTED' => Http::response([
            'status' => true,
            'data' => [
                'status' => 'success',
                'amount' => 400000,
                'reference' => 'TXN_UNPOSTED',
            ],
        ]),
    ]);

    $member = User::factory()->member()->employed()->create();
    $transaction = PaystackTransaction::create([
        'reference' => 'TXN_UNPOSTED',
        'user_id' => $member->id,
        'family_id' => $member->family_id,
        'type' => TransactionType::Contribution,
        'amount' => 4000,
        'gross_amount_kobo' => 400000,
        'status' => TransactionStatus::Pending,
    ]);
    $settlement = Mockery::mock(PaystackContributionSettlementService::class);
    $settlement->shouldReceive('settle')
        ->once()
        ->with('TXN_UNPOSTED', Mockery::type('array'))
        ->andReturn($transaction);
    app()->instance(PaystackContributionSettlementService::class, $settlement);

    $this->actingAs($member)
        ->get(route('pay.callback', ['reference' => 'TXN_UNPOSTED']))
        ->assertRedirect(route('contributions.my'))
        ->assertInertiaFlash('payment_result', ['kind' => 'error', 'message' => 'Payment was verified but not recorded. Contact your family admin before trying again.']);
});

it('does not claim an unallocated successful settlement updated contributions', function () {
    Http::fake(['api.paystack.co/transaction/verify/TXN_UNALLOCATED' => Http::response([
        'status' => true, 'data' => ['status' => 'success', 'amount' => 400000, 'reference' => 'TXN_UNALLOCATED'],
    ])]);
    $member = User::factory()->member()->employed()->create();
    $transaction = PaystackTransaction::create([
        'reference' => 'TXN_UNALLOCATED', 'user_id' => $member->id, 'family_id' => $member->family_id,
        'type' => TransactionType::Contribution, 'amount' => 4000, 'status' => TransactionStatus::Pending,
    ]);
    $unallocated = clone $transaction;
    $unallocated->status = TransactionStatus::Success;
    $settlement = Mockery::mock(PaystackContributionSettlementService::class);
    $settlement->shouldReceive('settle')->once()->andReturn($unallocated);
    app()->instance(PaystackContributionSettlementService::class, $settlement);

    $this->actingAs($member)->get(route('pay.callback', ['reference' => 'TXN_UNALLOCATED']))
        ->assertRedirect(route('contributions.my'))
        ->assertInertiaFlash('payment_result', ['kind' => 'error', 'message' => 'Payment was verified but not recorded. Contact your family admin before trying again.']);
    expect($transaction->refresh()->payment_batch_id)->toBeNull();
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
        ->assertRedirect(route('contributions.my'))
        ->assertInertiaFlash('payment_result', ['kind' => 'success', 'message' => 'Payment successful! Your contributions have been updated.']);

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
        ->assertRedirect(route('contributions.my'))
        ->assertInertiaFlash('payment_result', ['kind' => 'error', 'message' => 'Could not confirm your payment. Check your payment history or contact your family admin before trying again.']);

    expect($transaction->refresh()->status)->toBe(TransactionStatus::Failed)
        ->and(Payment::where('contribution_id', $contribution->id)->exists())->toBeFalse();
});

it('does not claim a legacy successful transaction was recorded without an allocation', function () {
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
        ->assertRedirect(route('contributions.my'))
        ->assertInertiaFlash('payment_result', ['kind' => 'error', 'message' => 'Payment may have been successful, but we could not confirm it was recorded. Check your payment history or contact your family admin before trying again.']);
});

it('does not overwrite an unallocated legacy success when a non-success callback arrives', function () {
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
    $transaction = PaystackTransaction::create([
        'reference' => 'TXN_CONFIRMED',
        'user_id' => $member->id,
        'family_id' => $member->family_id,
        'type' => TransactionType::Contribution,
        'amount' => 4000,
        'status' => TransactionStatus::Success,
    ]);

    $this->actingAs($member)
        ->get(route('pay.callback', ['reference' => 'TXN_CONFIRMED']))
        ->assertRedirect(route('contributions.my'))
        ->assertInertiaFlash('payment_result', ['kind' => 'error', 'message' => 'Payment may have been successful, but we could not confirm it was recorded. Check your payment history or contact your family admin before trying again.']);

    expect($transaction->refresh()->status)->toBe(TransactionStatus::Success)
        ->and($transaction->payment_batch_id)->toBeNull();
    Http::assertNothingSent();
});

it('treats a nonterminal or missing provider status as unconfirmed', function (?string $status) {
    $data = $status === null ? [] : ['status' => $status];
    Http::fake(['api.paystack.co/transaction/verify/TXN_UNCONFIRMED' => Http::response(['status' => true, 'data' => $data])]);
    $member = User::factory()->member()->employed()->create();
    $transaction = PaystackTransaction::create([
        'reference' => 'TXN_UNCONFIRMED', 'user_id' => $member->id, 'family_id' => $member->family_id,
        'type' => TransactionType::Contribution, 'amount' => 4000, 'status' => TransactionStatus::Pending,
    ]);

    $this->actingAs($member)->get(route('pay.callback', ['reference' => $transaction->reference]))
        ->assertRedirect(route('contributions.my'))
        ->assertInertiaFlash('payment_result', ['kind' => 'error', 'message' => 'Could not confirm your payment. Check your payment history or contact your family admin before trying again.']);
    expect($transaction->refresh()->status)->toBe(TransactionStatus::Pending)
        ->and($transaction->payment_batch_id)->toBeNull();
})->with(['pending', 'ongoing', 'processing', 'queued', 'abandoned', 'reversed', 'unknown', null]);

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
        ->assertRedirect(route('contributions.my'))
        ->assertInertiaFlash('payment_result', ['kind' => 'error', 'message' => 'Payment was not successful. Please try again.']);
});

it('explains an uncertain outcome when callback verification throws', function () {
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
        ->assertRedirect(route('contributions.my'))
        ->assertInertiaFlash('payment_result', ['kind' => 'error', 'message' => 'Could not confirm your payment. Check your payment history or contact your family admin before trying again.']);
});
