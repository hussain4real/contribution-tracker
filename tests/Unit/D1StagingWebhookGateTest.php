<?php

declare(strict_types=1);

use App\Services\PaystackService;
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
