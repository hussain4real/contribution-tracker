<?php

declare(strict_types=1);

use App\Services\PaystackService;
use FamilyFunds\D1Staging\D1StagingConnection;
use FamilyFunds\D1Staging\D1StagingRequest;
use FamilyFunds\D1Staging\D1StagingRuntime;
use FamilyFunds\D1Staging\D1StagingServiceProvider;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response as HttpResponse;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use NotificationChannels\WebPush\PushSubscription;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

pest()->extend(TestCase::class);

beforeEach(function (): void {
    require_once __DIR__.'/../../deployment/d1-proof/autoload.php';
    config([
        'd1-staging.database' => 'familyfunds-d1-web-staging',
        'd1-staging.webhooks.enabled' => true,
        'd1-staging.webhooks.relay_token' => str_repeat('a', 64),
        'd1-staging.webhooks.paystack_test_key' => 'sk_test_fixture',
        'd1-staging.webhooks.meta_app_secret' => str_repeat('b', 32),
    ]);
});

it('keeps D1 reachable when the test payment verification endpoint is enabled', function (): void {
    config([
        'd1-staging.enabled' => true,
        'd1-staging.url' => 'https://d1-staging.example.invalid',
        'd1-staging.r2.bucket' => '',
    ]);
    Http::fake([]);
    (new D1StagingServiceProvider($this->app))->boot();
    expect(app(PaystackService::class)->verifyWebhookSignature('staging charge', hash_hmac('sha512', 'staging charge', 'sk_test_fixture')))->toBeTrue();
    foreach (['https://d1-staging.example.invalid/query', 'https://api.paystack.co/transaction/verify/D1-WEBHOOK-UAT-fixture'] as $url) {
        $response = Http::setHandler(new MockHandler([new HttpResponse(200, [], '{}')]))->get($url);
        expect($response->successful())->toBeTrue();
    }
    expect(fn () => Http::setHandler(new MockHandler([new HttpResponse(200)]))->get('https://unapproved.example.invalid'))
        ->toThrow(StrayRequestException::class);
});

it('resolves push subscription lookups on the current staging snapshot', function (): void {
    config([
        'd1-staging.enabled' => true,
        'd1-staging.url' => 'https://familyfunds-d1-staging-sql.familyfunds-lab.workers.dev',
        'd1-staging.token' => 'fixture',
        'database.connections.d1-staging-snapshot' => ['driver' => 'sqlite', 'database' => ':memory:'],
        'webpush.database_connection' => 'd1-staging',
    ]);
    (new D1StagingServiceProvider($this->app))->register();
    DB::setDefaultConnection('d1-staging-snapshot');
    DB::statement('create table push_subscriptions (id integer primary key)');
    expect(PushSubscription::query()->count())->toBe(0);
});

it('admits only the explicitly enabled private test-provider relay', function (string $path, string $method, ?string $token, ?string $override, mixed $value, bool $allowed): void {
    if ($override !== null) {
        config([$override => $value]);
    }
    $request = Request::create($path, $method);
    if ($token !== null) {
        $request->headers->set('X-D1-Staging-Relay', $token);
    }
    $run = fn (): Response => (new D1StagingRequest(new D1StagingRuntime))->handle($request, fn () => response('accepted'), 'gate');
    if ($allowed) {
        expect($run()->getStatusCode())->toBe(200);
    } else {
        expect($run)->toThrow(HttpException::class);
    }
})->with([
    'Paystack test relay' => ['/webhooks/paystack', 'POST', str_repeat('a', 64), null, null, true],
    'independent Meta relay' => ['/webhooks/whatsapp', 'POST', str_repeat('a', 64), null, null, true],
    'public caller' => ['/webhooks/paystack', 'POST', null, null, null, false],
    'wrong relay token' => ['/webhooks/paystack', 'POST', str_repeat('c', 64), null, null, false],
    'disabled proof' => ['/webhooks/paystack', 'POST', str_repeat('a', 64), 'd1-staging.webhooks.enabled', false, false],
    'scale database' => ['/webhooks/paystack', 'POST', str_repeat('a', 64), 'd1-staging.database', 'familyfunds-d1-scale-lab', false],
    'live Paystack key' => ['/webhooks/paystack', 'POST', str_repeat('a', 64), 'd1-staging.webhooks.paystack_test_key', 'sk_live_fixture', false],
    'missing Meta signing secret' => ['/webhooks/whatsapp', 'POST', str_repeat('a', 64), 'd1-staging.webhooks.meta_app_secret', '', false],
    'weak relay token' => ['/webhooks/paystack', 'POST', 'short', 'd1-staging.webhooks.relay_token', 'short', false],
    'verification remains at edge' => ['/webhooks/whatsapp', 'GET', str_repeat('a', 64), null, null, false],
    'platform still blocked' => ['/platform/dashboard', 'POST', str_repeat('a', 64), null, null, false],
    'OAuth still blocked' => ['/oauth/token', 'POST', str_repeat('a', 64), null, null, false],
]);

it('requires the replacement gateway, bucket and write admission for the replacement relay', function (): void {
    $database = D1StagingConnection::REPLACEMENT_DATABASE;
    $target = [
        'd1-staging.database' => $database,
        'd1-staging.url' => D1StagingConnection::TARGETS[$database],
        'd1-staging.r2.bucket' => $database.'-files',
        'd1-staging.r2.admission' => '1',
    ];
    $request = Request::create('/webhooks/whatsapp', 'POST');
    $request->headers->set('X-D1-Staging-Relay', str_repeat('a', 64));
    $run = fn (): Response => (new D1StagingRequest(new D1StagingRuntime))->handle($request, fn () => response('accepted'), 'gate');
    config($target);
    expect($run()->getStatusCode())->toBe(200);
    foreach (['d1-staging.url', 'd1-staging.r2.bucket', 'd1-staging.r2.admission'] as $setting) {
        config([$setting => 'wrong'] + $target);
        expect($run)->toThrow(HttpException::class);
    }
});

it('uses the checkout key for API requests when a separate webhook test key is configured', function (bool $replacement): void {
    config([
        'd1-staging.enabled' => true,
        'd1-staging.url' => 'https://d1-staging.example.invalid',
        'd1-staging.r2.bucket' => '',
        'd1-staging.paystack_test_checkout' => true,
        'd1-staging.webhooks.paystack_signing_secret' => 'whsec_fixture',
        'services.paystack.secret_key' => 'sk_test_checkout',
        'services.paystack.public_key' => 'pk_test_checkout',
        'services.paystack.base_url' => 'https://api.paystack.co',
    ]);
    if ($replacement) {
        $database = D1StagingConnection::REPLACEMENT_DATABASE;
        config([
            'd1-staging.database' => $database,
            'd1-staging.url' => D1StagingConnection::TARGETS[$database],
            'd1-staging.r2.bucket' => $database.'-files',
            'd1-staging.r2.admission' => '1',
        ]);
    }
    Http::fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => true])]);

    (new D1StagingServiceProvider($this->app))->boot();
    $paystack = app(PaystackService::class);
    $paystack->initializeTransaction(['email' => 'member@example.com', 'amount' => 100]);

    Http::assertSent(fn (Illuminate\Http\Client\Request $request): bool => $request->hasHeader('Authorization', 'Bearer sk_test_checkout'));
    $mac = hash_hmac('sha512', '1793730000.staging charge', 'whsec_fixture');
    expect($paystack->verifyWebhookSignature('staging charge', 't=1793730000,v1=whk_fixture:'.$mac))->toBeTrue();
    $valid = 'v1=whk_fixture:'.$mac;
    $other = 'v1=whk_old:'.str_repeat('0', 128);
    expect($paystack->verifyWebhookSignature('staging charge', "t=1793730000,$other,$valid"))->toBeTrue();
    expect($paystack->verifyWebhookSignature('staging charge', "t=1793730000,$valid,$other"))->toBeTrue();
    expect($paystack->verifyWebhookSignature('staging charge', "t=1793730000,$other,$other"))->toBeFalse();
    expect($paystack->verifyWebhookSignature('staging charge ', "t=1793730000,$other,$valid"))->toBeFalse();
    expect($paystack->verifyWebhookSignature('staging charge', "t=1793730000,$valid,bogus"))->toBeFalse();
    expect($paystack->verifyWebhookSignature('staging charge', 't=1793730001,v1=whk_fixture:'.$mac))->toBeFalse();
    expect($paystack->verifyWebhookSignature('staging charge', hash_hmac('sha512', 'staging charge', 'sk_test_checkout')))->toBeFalse();
})->with([false, true]);

it('permits only the isolated Paystack test checkout endpoints', function (): void {
    config([
        'd1-staging.enabled' => true,
        'd1-staging.url' => 'https://d1-staging.example.invalid',
        'd1-staging.r2.bucket' => '',
        'd1-staging.webhooks.enabled' => false,
        'd1-staging.paystack_test_checkout' => true,
        'services.paystack.secret_key' => 'sk_test_fixture',
        'services.paystack.public_key' => 'pk_test_fixture',
        'services.paystack.base_url' => 'https://api.paystack.co',
    ]);
    Http::fake([]);
    (new D1StagingServiceProvider($this->app))->boot();
    foreach ([
        'https://api.paystack.co/transaction/initialize',
        'https://api.paystack.co/transaction/verify/test-reference',
        'https://api.paystack.co/bank',
        'https://api.paystack.co/bank?currency=NGN',
    ] as $url) {
        $client = Http::setHandler(new MockHandler([new HttpResponse(200, [], '{}')]));
        $response = str_ends_with($url, '/transaction/initialize') ? $client->post($url, []) : $client->get($url);
        expect($response->successful())->toBeTrue();
    }
    foreach (['https://api.paystack.co/transfer', 'https://api.paystack.co.evil.invalid/transaction/initialize'] as $url) {
        expect(fn () => Http::setHandler(new MockHandler([new HttpResponse(200)]))->get($url))->toThrow(StrayRequestException::class);
    }
});

it('rejects invalid staging test checkout configuration', function (string $key, mixed $value): void {
    config([
        'd1-staging.enabled' => true,
        'd1-staging.url' => 'https://d1-staging.example.invalid',
        'd1-staging.r2.bucket' => '',
        'd1-staging.webhooks.enabled' => false,
        'd1-staging.paystack_test_checkout' => true,
        'services.paystack.secret_key' => 'sk_test_fixture',
        'services.paystack.public_key' => 'pk_test_fixture',
        'services.paystack.base_url' => 'https://api.paystack.co',
        $key => $value,
    ]);
    expect(fn () => (new D1StagingServiceProvider($this->app))->boot())->toThrow(LogicException::class);
})->with([
    'different database' => ['d1-staging.database', 'familyfunds-production'],
    'different origin' => ['services.paystack.base_url', 'https://other.example'],
    'live secret' => ['services.paystack.secret_key', 'sk_live_fixture'],
    'missing secret' => ['services.paystack.secret_key', null],
    'live public key' => ['services.paystack.public_key', 'pk_live_fixture'],
    'missing public key' => ['services.paystack.public_key', null],
]);

it('continues to block Paystack checkout when test checkout is disabled', function (): void {
    config([
        'd1-staging.enabled' => true,
        'd1-staging.url' => 'https://d1-staging.example.invalid',
        'd1-staging.r2.bucket' => '',
        'd1-staging.webhooks.enabled' => false,
        'd1-staging.paystack_test_checkout' => false,
    ]);
    Http::fake([]);
    (new D1StagingServiceProvider($this->app))->boot();
    expect(fn () => Http::setHandler(new MockHandler([new HttpResponse(200)]))->get('https://api.paystack.co/transaction/initialize'))
        ->toThrow(StrayRequestException::class);
});
