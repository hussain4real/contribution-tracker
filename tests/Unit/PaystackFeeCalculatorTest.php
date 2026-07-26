<?php

declare(strict_types=1);

use App\Services\PaystackFeeCalculator;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config([
        'services.paystack.fee_policy' => 'payer_pays',
        'services.paystack.local_fee_basis_points' => 150,
        'services.paystack.local_fee_fixed_kobo' => 10_000,
        'services.paystack.local_fee_fixed_waiver_threshold_kobo' => 250_000,
        'services.paystack.local_fee_cap_kobo' => 200_000,
    ]);
});

it('grosses up a local contribution so the family settles the contribution amount', function () {
    $estimate = app(PaystackFeeCalculator::class)->estimateForContributionAmount(4000);

    expect($estimate)->toMatchArray([
        'contribution_amount_kobo' => 400_000,
        'gross_amount_kobo' => 416_244,
        'estimated_fee_kobo' => 16_244,
        'settled_amount_kobo' => 400_000,
        'fee_policy' => 'payer_pays',
    ]);
});

it('returns a zero estimate for an empty contribution amount', function () {
    $estimate = app(PaystackFeeCalculator::class)->estimateForContributionAmount(0);

    expect($estimate)->toMatchArray([
        'contribution_amount_kobo' => 0,
        'gross_amount_kobo' => 0,
        'estimated_fee_kobo' => 0,
        'settled_amount_kobo' => 0,
        'fee_policy' => 'payer_pays',
    ]);
});

it('exposes the public fee configuration used by the pay page', function () {
    $config = app(PaystackFeeCalculator::class)->publicConfig();

    expect($config)->toBe([
        'policy' => 'payer_pays',
        'basis_points' => 150,
        'fixed_kobo' => 10_000,
        'waiver_threshold_kobo' => 250_000,
        'cap_kobo' => 200_000,
    ]);
});

it('waives the fixed local fee for small gross charges', function () {
    $calculator = app(PaystackFeeCalculator::class);

    expect($calculator->feeForGrossAmountKobo(200_000))->toBe(3_000)
        ->and($calculator->feeForGrossAmountKobo(250_000))->toBe(13_750);
});

it('does not charge a fee for a zero gross amount', function () {
    expect(app(PaystackFeeCalculator::class)->feeForGrossAmountKobo(0))->toBe(0);
});

it('caps local Paystack fees at the configured cap', function () {
    $fee = app(PaystackFeeCalculator::class)->feeForGrossAmountKobo(20_000_000);

    expect($fee)->toBe(200_000);
});
