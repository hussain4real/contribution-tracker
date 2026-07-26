<?php

declare(strict_types=1);

use App\Jobs\SyncPaystackSubaccount;
use App\Models\Family;
use App\Services\PaystackService;
use Illuminate\Support\Facades\Log;

it('creates a paystack subaccount when the family does not have one', function () {
    $family = Family::factory()->create([
        'name' => 'Smith Family',
        'bank_code' => '058',
        'account_number' => '0123456789',
        'paystack_subaccount_code' => null,
    ]);
    $paystack = typedMock(PaystackService::class);
    $paystack->shouldReceive('createSubaccount')
        ->once()
        ->with([
            'business_name' => 'Smith Family',
            'bank_code' => '058',
            'account_number' => '0123456789',
            'percentage_charge' => 0,
        ])
        ->andReturn(['data' => ['subaccount_code' => 'ACCT_test']]);

    (new SyncPaystackSubaccount($family))->handle($paystack);

    expect($family->refresh()->paystack_subaccount_code)->toBe('ACCT_test');
});

it('updates an existing paystack subaccount', function () {
    $family = Family::factory()->create([
        'name' => 'Smith Family',
        'bank_code' => '058',
        'account_number' => '0123456789',
        'paystack_subaccount_code' => 'ACCT_existing',
    ]);
    $paystack = typedMock(PaystackService::class);
    $paystack->shouldReceive('updateSubaccount')
        ->once()
        ->with('ACCT_existing', [
            'business_name' => 'Smith Family',
            'bank_code' => '058',
            'account_number' => '0123456789',
            'percentage_charge' => 0,
        ]);

    (new SyncPaystackSubaccount($family))->handle($paystack);

    expect($family->refresh()->paystack_subaccount_code)->toBe('ACCT_existing');
});

it('throws when paystack does not return a subaccount code', function () {
    $family = Family::factory()->create([
        'bank_code' => '058',
        'account_number' => '0123456789',
    ]);
    $paystack = typedMock(PaystackService::class);
    $paystack->shouldReceive('createSubaccount')
        ->once()
        ->andReturn(['data' => []]);

    (new SyncPaystackSubaccount($family))->handle($paystack);
})->throws(RuntimeException::class, 'did not include subaccount_code');

it('skips syncing when bank details are incomplete', function () {
    $family = Family::factory()->create([
        'bank_code' => null,
        'account_number' => '0123456789',
    ]);
    $paystack = typedMock(PaystackService::class);
    $paystack->shouldNotReceive('createSubaccount');
    $paystack->shouldNotReceive('updateSubaccount');

    Log::shouldReceive('warning')
        ->once()
        ->with('Skipping Paystack subaccount sync because bank details are incomplete', [
            'family_id' => $family->id,
        ]);

    (new SyncPaystackSubaccount($family))->handle($paystack);
});

it('throws when paystack does not return a data payload', function () {
    $family = Family::factory()->create([
        'bank_code' => '058',
        'account_number' => '0123456789',
    ]);
    $paystack = typedMock(PaystackService::class);
    $paystack->shouldReceive('createSubaccount')
        ->once()
        ->andReturn(['data' => null]);

    (new SyncPaystackSubaccount($family))->handle($paystack);
})->throws(RuntimeException::class, 'data payload');

it('logs failed job details', function () {
    $family = Family::factory()->create();
    $exception = new RuntimeException('Paystack down');

    Log::shouldReceive('error')
        ->once()
        ->with('Failed to sync Paystack subaccount', [
            'family_id' => $family->id,
            'error' => 'Paystack down',
        ]);

    (new SyncPaystackSubaccount($family))->failed($exception);
});
