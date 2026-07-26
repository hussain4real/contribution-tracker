<?php

declare(strict_types=1);

use App\Services\PaystackService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
    $data = resultArray($result, 'data');

    expect($result['status'])->toBeTrue()
        ->and($data['access_code'] ?? null)->toBe('abc123')
        ->and($data['reference'] ?? null)->toBe('TXN_TEST123');

    Http::assertSent(function (Request $request) {
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
    $data = resultArray($result, 'data');

    expect($data['status'] ?? null)->toBe('success')
        ->and($data['amount'] ?? null)->toBe(400000);
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
    $data = resultArray($result, 'data');

    expect($data['subaccount_code'] ?? null)->toBe('ACCT_test123');
});

it('updates a subaccount', function () {
    Http::fake([
        'api.paystack.co/subaccount/ACCT_test123' => Http::response([
            'status' => true,
            'message' => 'Subaccount updated',
            'data' => ['subaccount_code' => 'ACCT_test123'],
        ]),
    ]);

    $result = app(PaystackService::class)->updateSubaccount('ACCT_test123', [
        'business_name' => 'Updated Family',
    ]);

    expect($result['status'])->toBeTrue();
});

it('resolves an account number', function () {
    Http::fake([
        'api.paystack.co/bank/resolve*' => Http::response([
            'status' => true,
            'message' => 'Account number resolved',
            'data' => [
                'account_number' => '0123456789',
                'account_name' => 'Test Family',
            ],
        ]),
    ]);

    $result = app(PaystackService::class)->resolveAccountNumber('0123456789', '058');
    $data = resultArray($result, 'data');

    expect($data['account_name'] ?? null)->toBe('Test Family');
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
    $banks = resultArray($result, 'data');
    $firstBank = firstResultArray($result, 'data');

    expect($banks)->toHaveCount(2)
        ->and($firstBank['code'] ?? null)->toBe('058');
});

it('verifies a valid webhook signature', function () {
    $service = app(PaystackService::class);

    $payload = '{"event":"charge.success"}';
    $signature = hash_hmac('sha512', $payload, 'whsec_test123');

    expect($service->verifyWebhookSignature($payload, $signature))->toBeTrue();
});

it('rejects webhook signatures when no webhook secret is configured', function () {
    config(['services.paystack.webhook_secret' => null]);

    Log::shouldReceive('warning')
        ->once()
        ->with('Paystack webhook secret not configured');

    expect(app(PaystackService::class)->verifyWebhookSignature('{}', 'signature'))->toBeFalse();
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

/**
 * @param  array<string, mixed>  $payload
 * @param  array<string, mixed>  $responseData
 */
it('covers subscription and customer paystack endpoints', function (string $method, string $url, array $payload, array $responseData) {
    Http::fake([
        $url => Http::response([
            'status' => true,
            'message' => 'OK',
            'data' => $responseData,
        ]),
    ]);

    $service = app(PaystackService::class);
    $result = match ($method) {
        'createPlan' => $service->createPlan([
            'name' => stringValue($payload, 'name'),
            'amount' => intValue($payload, 'amount'),
            'interval' => stringValue($payload, 'interval'),
        ]),
        'createSubscription' => $service->createSubscription([
            'customer' => stringValue($payload, 'customer'),
            'plan' => stringValue($payload, 'plan'),
        ]),
        'disableSubscription' => $service->disableSubscription([
            'code' => stringValue($payload, 'code'),
            'token' => stringValue($payload, 'token'),
        ]),
        'createCustomer' => $service->createCustomer([
            'email' => stringValue($payload, 'email'),
            'first_name' => stringValue($payload, 'first_name'),
        ]),
        default => throw new InvalidArgumentException("Unsupported Paystack method [{$method}]."),
    };
    $data = resultArray($result, 'data');

    expect($result['status'])->toBeTrue()
        ->and($data)->toBe($responseData);
})->with([
    'create plan' => [
        'createPlan',
        'api.paystack.co/plan',
        ['name' => 'Starter', 'amount' => 200000, 'interval' => 'monthly'],
        ['plan_code' => 'PLN_test'],
    ],
    'create subscription' => [
        'createSubscription',
        'api.paystack.co/subscription',
        ['customer' => 'CUS_test', 'plan' => 'PLN_test'],
        ['subscription_code' => 'SUB_test', 'email_token' => 'token_test'],
    ],
    'disable subscription' => [
        'disableSubscription',
        'api.paystack.co/subscription/disable',
        ['code' => 'SUB_test', 'token' => 'token_test'],
        [],
    ],
    'create customer' => [
        'createCustomer',
        'api.paystack.co/customer',
        ['email' => 'test@example.com', 'first_name' => 'Test'],
        ['customer_code' => 'CUS_test'],
    ],
]);
