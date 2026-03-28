<?php

use App\Services\PaystackService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.paystack.secret_key' => 'sk_test_secret',
        'services.paystack.base_url' => 'https://api.paystack.co',
        'services.paystack.webhook_secret' => 'whsec_test123',
    ]);

    Http::preventStrayRequests();
});

it('initializes a transaction', function () {
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'message' => 'Authorization URL created',
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/abc123',
                'access_code' => 'abc123',
                'reference' => 'TXN_TEST123',
            ],
        ]),
    ]);

    $service = app(PaystackService::class);

    $result = $service->initializeTransaction([
        'email' => 'test@example.com',
        'amount' => 400000,
        'reference' => 'TXN_TEST123',
    ]);

    expect($result['status'])->toBeTrue()
        ->and($result['data']['access_code'])->toBe('abc123')
        ->and($result['data']['reference'])->toBe('TXN_TEST123');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.paystack.co/transaction/initialize'
            && $request->hasHeader('Authorization')
            && $request['email'] === 'test@example.com'
            && $request['amount'] === 400000;
    });
});

it('verifies a transaction', function () {
    Http::fake([
        'api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true,
            'message' => 'Verification successful',
            'data' => [
                'status' => 'success',
                'amount' => 400000,
                'reference' => 'TXN_TEST123',
                'channel' => 'card',
                'currency' => 'NGN',
                'metadata' => [],
            ],
        ]),
    ]);

    $service = app(PaystackService::class);

    $result = $service->verifyTransaction('TXN_TEST123');

    expect($result['data']['status'])->toBe('success')
        ->and($result['data']['amount'])->toBe(400000);
});

it('creates a subaccount', function () {
    Http::fake([
        'api.paystack.co/subaccount' => Http::response([
            'status' => true,
            'message' => 'Subaccount created',
            'data' => [
                'subaccount_code' => 'ACCT_test123',
            ],
        ]),
    ]);

    $service = app(PaystackService::class);

    $result = $service->createSubaccount([
        'business_name' => 'Test Family',
        'bank_code' => '058',
        'account_number' => '0123456789',
        'percentage_charge' => 0,
    ]);

    expect($result['data']['subaccount_code'])->toBe('ACCT_test123');
});

it('lists banks', function () {
    Http::fake([
        'api.paystack.co/bank*' => Http::response([
            'status' => true,
            'message' => 'Banks retrieved',
            'data' => [
                ['name' => 'GTBank', 'code' => '058', 'active' => true],
                ['name' => 'Access Bank', 'code' => '044', 'active' => true],
            ],
        ]),
    ]);

    $service = app(PaystackService::class);

    $result = $service->listBanks();

    expect($result['data'])->toHaveCount(2)
        ->and($result['data'][0]['code'])->toBe('058');
});

it('verifies a valid webhook signature', function () {
    $service = app(PaystackService::class);

    $payload = '{"event":"charge.success"}';
    $signature = hash_hmac('sha512', $payload, 'whsec_test123');

    expect($service->verifyWebhookSignature($payload, $signature))->toBeTrue();
});

it('rejects an invalid webhook signature', function () {
    $service = app(PaystackService::class);

    $payload = '{"event":"charge.success"}';

    expect($service->verifyWebhookSignature($payload, 'invalid_signature'))->toBeFalse();
});

it('throws on API error response', function () {
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => false,
            'message' => 'Invalid amount',
        ], 400),
    ]);

    $service = app(PaystackService::class);

    $service->initializeTransaction([
        'email' => 'test@example.com',
        'amount' => 0,
    ]);
})->throws(RuntimeException::class);
