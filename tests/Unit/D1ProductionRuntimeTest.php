<?php

declare(strict_types=1);

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Payment;
use App\Models\PaystackTransaction;
use App\Models\User;
use App\Notifications\ContributionReminderNotification;
use App\Services\PaymentAllocationPreview;
use App\Services\PaymentAllocationService;
use App\Services\PaystackContributionSettlementService;
use App\Services\PaystackService;
use FamilyFunds\D1Staging\D1ProductionRequest;
use FamilyFunds\D1Staging\D1ProductionServiceProvider;
use FamilyFunds\D1Staging\D1RuntimeCacheStore;
use FamilyFunds\D1Staging\D1RuntimeClient;
use FamilyFunds\D1Staging\D1RuntimeProfile;
use FamilyFunds\D1Staging\D1RuntimeRateLimiter;
use FamilyFunds\D1Staging\D1StagingConnection;
use FamilyFunds\D1Staging\D1StagingRuntime;
use FamilyFunds\D1Staging\D1StagingSessionHandler;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Session\EncryptedStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

pest()->extend(TestCase::class);

beforeEach(function (): void {
    require_once __DIR__.'/../../deployment/d1-proof/autoload.php';
    $this->app['env'] = 'production';
    config([
        'app.env' => 'production', 'app.debug' => false,
        'd1-staging.enabled' => false, 'd1-production.enabled' => true,
        'd1-production.url' => D1RuntimeProfile::PRODUCTION_URL,
        'd1-production.database' => D1RuntimeProfile::PRODUCTION_DATABASE,
        'd1-production.token' => 'synthetic-production-fixture-token',
        'd1-production.r2.bucket' => D1RuntimeProfile::PRODUCTION_BUCKET,
        'd1-production.r2.key' => 'fixture-key', 'd1-production.r2.secret' => 'fixture-secret',
        'd1-production.delivery_enabled' => false,
        'd1-production.relay_token' => str_repeat('r', 40),
    ]);
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('pragma foreign_keys=on');
    $pdo->exec('create table jobs(id integer primary key autoincrement,queue text,payload text,attempts integer,reserved_at integer,available_at integer,created_at integer,lease_token text)');
    $pdo->exec('create table sessions(id text primary key,payload text,last_activity integer)');
    $pdo->exec('create table "families"("id" integer primary key autoincrement,"name" text)');
    $pdo->exec('create table "contributions"("id" integer primary key autoincrement,"family_id" integer,"amount" integer)');
    $pdo->exec("insert into families values(1,'First'),(2,'Second')");
    $pdo->exec('insert into contributions values(1,1,10),(2,2,20)');
    $this->remote = $pdo;
    Http::fake(function (ClientRequest $request) use ($pdo): PromiseInterface {
        expect(str_starts_with($request->url(), D1RuntimeProfile::PRODUCTION_URL.'/'))->toBeTrue();
        $body = $request->data();
        $queries = $body['batch'] ?? [$body];
        assert(is_array($queries));
        $results = [];
        $pdo->beginTransaction();
        try {
            foreach ($queries as $query) {
                assert(is_array($query) && is_string($query['sql']) && is_array($query['params']));
                $statement = $pdo->prepare($query['sql']);
                foreach ($query['params'] as $index => $value) {
                    $statement->bindValue($index + 1, $value, is_int($value) ? PDO::PARAM_INT : ($value === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
                }
                $statement->execute();
                $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
                $changes = $pdo->query('select changes()');
                assert($changes !== false);
                $results[] = ['results' => $rows, 'meta' => ['changes' => (int) $changes->fetchColumn()]];
            }
            $pdo->commit();

            return Http::response(['database' => D1RuntimeProfile::PRODUCTION_DATABASE, 'result' => isset($body['batch']) ? $results : $results[0]]);
        } catch (Throwable $exception) {
            $pdo->rollBack();

            return Http::response(['error' => $exception->getMessage()], 422);
        }
    });
    $provider = new D1ProductionServiceProvider($this->app);
    $provider->register();
    $provider->boot();
    D1StagingRuntime::install();
    D1RuntimeCacheStore::install(app(D1RuntimeClient::class));
});

it('uses production drivers and private storage without enabling the staging profile or delivery', function (): void {
    expect(DB::getDefaultConnection())->toBe('d1')
        ->and(config('session.encrypt'))->toBeTrue()
        ->and(config('cache.default'))->toBe('d1')
        ->and(config('filesystems.disks.local.bucket'))->toBe(D1RuntimeProfile::PRODUCTION_BUCKET)
        ->and(config('filesystems.disks.public.visibility'))->toBe('private')
        ->and(config('mail.default'))->toBe('array')
        ->and(config('d1-staging.enabled'))->toBeFalse();
});

it('allows only read-only bank listing with an independent test credential while candidate delivery stays disabled', function (): void {
    Http::swap(new Factory);
    config(['d1-production.bank_lookup_test_key' => 'sk_test_independent_bank_lookup']);
    (new D1ProductionServiceProvider($this->app))->boot();
    Http::fake(['https://api.paystack.co/bank?*' => Http::response(['status' => true, 'data' => [['name' => 'Test Bank', 'code' => '001', 'active' => true]]])]);
    $banks = app(PaystackService::class)->listBanks();
    expect($banks['data'])->toBe([['name' => 'Test Bank', 'code' => '001', 'active' => true]])
        ->and(config('d1-production.delivery_enabled'))->toBeFalse()
        ->and(config('mail.default'))->toBe('array');
    Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'GET' && $request->hasHeader('Authorization', 'Bearer sk_test_independent_bank_lookup'));
    expect(fn () => Http::post('https://api.paystack.co/bank?currency=NGN'))->toThrow(LogicException::class, 'read-only bank')
        ->and(fn () => Http::get('https://api.paystack.co/bank/resolve'))->toThrow(LogicException::class, 'read-only bank')
        ->and(fn () => Http::post('https://api.paystack.co/transaction/initialize'))->toThrow(LogicException::class, 'read-only bank');
});

it('refuses live credentials for isolated bank lookup', function (): void {
    config(['d1-production.bank_lookup_test_key' => 'sk_live_forbidden']);
    expect(fn () => (new D1ProductionServiceProvider($this->app))->boot())->toThrow(LogicException::class, 'test credential');
});

it('limits approved live provider inspection to bank and sender reads', function (): void {
    Http::swap(new Factory);
    config(['d1-production.migration_checks' => true, 'd1-production.provider_read_only_checks' => true,
        'services.paystack.secret_key' => 'sk_live_fixture', 'services.whatsapp.phone_number_id' => '123456789',
        'services.whatsapp.api_version' => 'v23.0']);
    (new D1ProductionServiceProvider($this->app))->boot();
    Http::fake(['*' => Http::response(['status' => true, 'id' => '123456789'])]);
    expect(Http::get('https://api.paystack.co/bank?currency=NGN')->successful())->toBeTrue()
        ->and(Http::get('https://graph.facebook.com/v23.0/123456789?fields=id')->successful())->toBeTrue()
        ->and(fn () => Http::post('https://api.paystack.co/transaction/initialize'))->toThrow(LogicException::class)
        ->and(fn () => Http::post('https://graph.facebook.com/v23.0/123456789/messages'))->toThrow(LogicException::class)
        ->and(fn () => Http::get('https://graph.facebook.com/v23.0/123456789?fields=access_token'))->toThrow(LogicException::class);
});

it('refuses read-only live provider checks outside the migration mirror', function (): void {
    config(['d1-production.provider_read_only_checks' => true, 'd1-production.migration_checks' => false]);
    expect(fn () => (new D1ProductionServiceProvider($this->app))->register())->toThrow(LogicException::class, 'isolated migration mirror');
});

it('blocks ledger and integration mutation on a real-data migration mirror while permitting authentication', function (): void {
    config(['d1-production.migration_checks' => true]);
    $middleware = app(D1ProductionRequest::class);
    foreach (['/first/payments', '/oauth/token', '/webhooks/paystack', '/first/family/settings'] as $path) {
        expect(fn () => $middleware->handle(Request::create($path, 'POST'), fn () => response('unexpected')))->toThrow(HttpException::class, 'inspection and authentication');
    }
    expect($middleware->handle(Request::create('/login', 'POST'), fn () => response('authentication'))->getContent())->toBe('authentication');
});

it('refuses an activated background worker or delivery during migration checks', function (string $setting): void {
    config(['d1-production.migration_checks' => true, $setting => true]);
    expect(fn () => (new D1ProductionServiceProvider($this->app))->register())->toThrow(LogicException::class, 'migration checks require');
})->with(['d1-production.queue_worker_enabled', 'd1-production.delivery_enabled', 'd1-production.webhooks_enabled', 'nightwatch.enabled']);

it('refuses crossing production and staging database targets in either direction', function (): void {
    expect(fn () => new D1StagingConnection(D1StagingConnection::TARGETS['familyfunds-d1-web-staging'], 'fixture', 'familyfunds-d1-web-staging'))->toThrow(RuntimeException::class);
    config(['d1-production.enabled' => false, 'd1-staging.enabled' => true]);
    $this->app['env'] = 'testing';
    expect(fn () => new D1StagingConnection(D1RuntimeProfile::PRODUCTION_URL, 'fixture', D1RuntimeProfile::PRODUCTION_DATABASE))->toThrow(RuntimeException::class);
});

it('refuses mixed profiles and debug mode before any request can use production data', function (): void {
    config(['d1-staging.enabled' => true]);
    expect(fn () => D1RuntimeProfile::assertEnabled())->toThrow(RuntimeException::class);
    config(['d1-staging.enabled' => false, 'app.debug' => true]);
    expect(fn () => D1RuntimeProfile::assertEnabled())->toThrow(RuntimeException::class);
});

it('preserves atomic cache counters and login rate limits across independent runtime objects', function (): void {
    $first = new D1RuntimeCacheStore(app(D1RuntimeClient::class));
    $second = new D1RuntimeCacheStore(app(D1RuntimeClient::class));
    expect($first->add('counter', 0, 60))->toBeTrue()
        ->and($second->add('counter', 999, 60))->toBeFalse()
        ->and($first->increment('counter'))->toBe(1)
        ->and($second->increment('counter'))->toBe(2)
        ->and($second->get('counter'))->toBe(2);
    $limiter = new RateLimiter(new Repository($first));
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $limiter->hit('login-fixture', 60);
    }
    $restarted = new RateLimiter(new Repository($second));
    expect($restarted->tooManyAttempts('login-fixture', 5))->toBeTrue();
    $this->remote->exec("update d1_runtime_cache set expires_at=unixepoch()-1 where key like '%login-fixture%'");
    expect($restarted->tooManyAttempts('login-fixture', 5))->toBeFalse();
});

it('fences cache locks so an expired owner cannot release or renew the new owner', function (): void {
    $store = new D1RuntimeCacheStore(app(D1RuntimeClient::class));
    $old = $store->lock('schedule', 30, 'old-owner');
    $new = $store->lock('schedule', 30, 'new-owner');
    expect($old->acquire())->toBeTrue()->and($new->acquire())->toBeFalse();
    $this->remote->exec('update d1_runtime_cache_locks set expires_at=unixepoch()-1');
    expect($new->acquire())->toBeTrue()
        ->and($old->release())->toBeFalse()
        ->and($old->refresh())->toBeFalse()
        ->and($new->refresh())->toBeTrue()
        ->and($new->release())->toBeTrue();
});

it('batches durable rate-limit hits without extending an active window across runtime restarts', function (): void {
    $limiter = app(RateLimiter::class);
    expect($limiter)->toBeInstanceOf(D1RuntimeRateLimiter::class);
    $before = count(Http::recorded());
    expect($limiter->hit('batched-login', 60))->toBe(1)
        ->and(count(Http::recorded()) - $before)->toBe(1);
    $this->remote->exec("update d1_runtime_cache set expires_at=unixepoch()+30 where key like '%batched-login%'");
    $restarted = new D1RuntimeRateLimiter(new Repository(new D1RuntimeCacheStore(app(D1RuntimeClient::class))));
    foreach (range(2, 5) as $hits) {
        expect($restarted->hit('batched-login', 60))->toBe($hits);
    }
    $expiration = $this->remote->query("select max(expires_at-unixepoch()) from d1_runtime_cache where key like '%batched-login%'");
    if ($expiration === false) {
        throw new RuntimeException('Unable to inspect the rate-limit expiration.');
    }
    expect($restarted->tooManyAttempts('batched-login', 5))->toBeTrue()
        ->and($restarted->availableIn('batched-login'))->toBeGreaterThan(0)
        ->and((int) $expiration->fetchColumn())->toBeLessThanOrEqual(30);
    $this->remote->exec("update d1_runtime_cache set expires_at=unixepoch()-1 where key like '%batched-login%'");
    expect($restarted->tooManyAttempts('batched-login', 5))->toBeFalse()
        ->and($restarted->hit('batched-login', 60))->toBe(1);
    $before = count(Http::recorded());
    $restarted->clear('batched-login');
    expect(count(Http::recorded()) - $before)->toBe(1)
        ->and($restarted->attempts('batched-login'))->toBe(0)
        ->and($restarted->availableIn('batched-login'))->toBe(0);
});

it('preserves framework rate-limit behavior for counter resets and unusual keys', function (): void {
    $optimized = new D1RuntimeRateLimiter(new Repository(new D1RuntimeCacheStore(app(D1RuntimeClient::class), 'optimized:')));
    $reference = new RateLimiter(new Repository(new D1RuntimeCacheStore(app(D1RuntimeClient::class), 'reference:')));
    foreach ([$reference, $optimized] as $limiter) {
        expect($limiter->hit('utilisateur-é', 60))->toBe(1)
            ->and($limiter->decrement('utilisateur-é', 60))->toBe(0);
        $limiter->resetAttempts('utilisateur-é');
        expect($limiter->increment('utilisateur-é', new DateInterval('PT60S'), 2))->toBe(2)
            ->and($limiter->remaining('utilisateur-é', 5))->toBe(3)
            ->and($limiter->tooManyAttempts('utilisateur-é', 2))->toBeTrue();
        $limiter->clear('utilisateur-é');
        expect($limiter->hit('utilisateur-é', 0))->toBe(0);
    }
});

it('rolls back a batched rate-limit hit and fails closed on remote failure', function (): void {
    $this->remote->exec("create trigger reject_fixture_counter before insert on d1_runtime_cache when new.key='familyfunds-production:rejected-login' begin select raise(abort,'synthetic counter failure'); end");
    expect(fn () => app(RateLimiter::class)->hit('rejected-login', 60))->toThrow(RuntimeException::class, 'commit outcome may be unknown');
    $remaining = $this->remote->query("select count(*) from d1_runtime_cache where key like '%rejected-login%'");
    if ($remaining === false) {
        throw new RuntimeException('Unable to inspect the rejected rate-limit write.');
    }
    expect((int) $remaining->fetchColumn())->toBe(0);
});

it('retains serialized values and namespaces across cache flushes', function (): void {
    $first = new D1RuntimeCacheStore(app(D1RuntimeClient::class), 'first:');
    $second = new D1RuntimeCacheStore(app(D1RuntimeClient::class), 'second:');
    $first->forever('value', ['private' => 'fixture']);
    $second->put('value', false, 60);
    expect($first->get('value'))->toBe(['private' => 'fixture'])->and($second->get('value'))->toBeFalse();
    $first->flush();
    expect($first->get('value'))->toBeNull()->and($second->get('value'))->toBeFalse();
});

it('keeps production sessions encrypted across independently constructed handlers', function (): void {
    $handler = fn (): D1StagingSessionHandler => new D1StagingSessionHandler(D1RuntimeProfile::PRODUCTION_URL, 'fixture', D1RuntimeProfile::PRODUCTION_DATABASE, 60);
    $key = new Encrypter(random_bytes(32), 'AES-256-CBC');
    $first = new EncryptedStore('production', $handler(), $key);
    $first->setId(str_repeat('a', 40));
    $first->start();
    $first->put('user', 'fixture-private-login');
    $first->save();
    $second = new EncryptedStore('production', $handler(), $key);
    $second->setId($first->getId());
    $second->start();
    expect($second->get('user'))->toBe('fixture-private-login');
    $rows = app(D1RuntimeClient::class)->rows('select payload from sessions');
    expect($rows[0]['payload'])->not->toContain('fixture-private-login');
});

it('commits deferred transaction closures through the same fenced financial planner', function (): void {
    DB::transaction(function (): void {
        DB::table('contributions')->where('id', 1)->update(['amount' => 55]);
        DB::table('contributions')->where('id', 2)->update(['amount' => 65]);
    });
    expect(DB::table('contributions')->sum('amount'))->toBe(120)
        ->and(config('d1-production.planning'))->toBeFalse();
    expect(fn () => DB::transaction(fn () => null, 2))->toThrow(LogicException::class);
    expect(fn () => DB::beginTransaction())->toThrow(LogicException::class);
});

it('rolls back every standalone transaction write when its callback throws', function (): void {
    expect(fn () => DB::transaction(function (): void {
        DB::table('contributions')->where('id', 1)->update(['amount' => 55]);
        DB::table('contributions')->where('id', 2)->delete();

        throw new RuntimeException('synthetic failed financial transaction');
    }))->toThrow(RuntimeException::class, 'synthetic failed financial transaction');

    expect(DB::table('contributions')->orderBy('id')->pluck('amount')->all())->toBe([10, 20])
        ->and(config('d1-production.planning'))->toBeFalse();
});

it('puts platform OAuth and MCP request work inside the transaction planner', function (string $path): void {
    $middleware = app(D1ProductionRequest::class);
    $response = $middleware->handle(Request::create($path, 'POST'), function (): Response {
        expect(DB::getDefaultConnection())->toBe('d1-staging-snapshot');
        DB::table('contributions')->where('id', 1)->update(['amount' => 77]);

        return response('accepted');
    });
    expect($response->getContent())->toBe('accepted')->and(DB::table('contributions')->where('id', 1)->value('amount'))->toBe(77);
})->with(['/platform/families', '/oauth/token', '/mcp/family-fund']);

it('refuses webhook forwarding without its separate production relay credential', function (): void {
    expect(fn () => app(D1ProductionRequest::class)->handle(Request::create('/webhooks/paystack', 'POST'), fn () => response('unexpected')))->toThrow(HttpException::class);
});

it('serves only the installed Livewire JavaScript path without leasing the ledger or reserving identifiers', function (): void {
    $path = EndpointResolver::scriptPath(minified: true);
    $before = count(Http::recorded());
    $response = app(D1ProductionRequest::class)->handle(Request::create($path.'?id=12345678'), fn (): Response => response('javascript'));
    expect($response->getContent())->toBe('javascript')
        ->and(count(Http::recorded()) - $before)->toBe(0);
    $before = count(Http::recorded());
    app(D1ProductionRequest::class)->handle(Request::create('/livewire-00000000/livewire.min.js'), fn (): Response => response('unmatched'));
    expect(count(Http::recorded()) - $before)->toBeGreaterThan(0);
});

it('preserves delivery ordering and queues early callbacks in the production D1 runtime', function (): void {
    $this->remote->exec('create table whatsapp_messages (id integer primary key autoincrement, wa_message_id text, status text, error_code text, error_message text, read_at text, created_at text, updated_at text)');
    $this->remote->exec("insert into whatsapp_messages (wa_message_id,status) values ('wamid.fixture','sent')");
    $runtime = new D1StagingRuntime;
    foreach (['read', 'sent'] as $status) {
        $runtime->run(function () use ($status): void {
            (new ProcessWhatsAppWebhook(['entry' => [['changes' => [[
                'field' => 'messages', 'value' => ['statuses' => [['id' => 'wamid.fixture', 'status' => $status]]],
            ]]]]]))->handle();
        });
    }
    $status = $this->remote->query("select status from whatsapp_messages where wa_message_id='wamid.fixture'");
    if ($status === false) {
        throw new RuntimeException('Could not query the synthetic message.');
    }
    expect($status->fetchColumn())->toBe('read');

    $runtime->run(function (): void {
        (new ProcessWhatsAppWebhook(['entry' => [['changes' => [[
            'field' => 'messages', 'value' => ['statuses' => [['id' => 'wamid.early', 'status' => 'delivered']]],
        ]]]]]))->handle();
    });
    $jobs = $this->remote->query('select count(*) from jobs');
    if ($jobs === false) {
        throw new RuntimeException('Could not query the synthetic queue.');
    }
    expect((int) $jobs->fetchColumn())->toBe(1);
});

function connectProductionRuntimeFixture(PDO $pdo): void
{
    Http::swap(new Factory);
    Http::fake(function (ClientRequest $request) use ($pdo): PromiseInterface {
        if (! str_starts_with($request->url(), D1RuntimeProfile::PRODUCTION_URL.'/')) {
            throw new RuntimeException('Unexpected fixture request.');
        }
        $body = $request->data();
        $queries = $body['batch'] ?? [$body];
        $results = [];
        $pdo->beginTransaction();
        try {
            if (! is_array($queries)) {
                throw new RuntimeException('Invalid fixture batch.');
            }
            foreach ($queries as $query) {
                if (! is_array($query) || ! is_string($query['sql'] ?? null) || ! is_array($query['params'] ?? null)) {
                    throw new RuntimeException('Invalid fixture query.');
                }
                $statement = $pdo->prepare($query['sql']);
                $statement->execute($query['params']);
                $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
                $changes = $pdo->query('select changes()');
                if ($changes === false) {
                    throw new RuntimeException('Unable to inspect fixture changes.');
                }
                $results[] = ['results' => $rows, 'meta' => ['changes' => (int) $changes->fetchColumn()]];
            }
            $pdo->commit();

            return Http::response(['database' => D1RuntimeProfile::PRODUCTION_DATABASE, 'result' => isset($body['batch']) ? $results : $results[0]]);
        } catch (Throwable $exception) {
            $pdo->rollBack();

            return Http::response(['error' => $exception->getMessage()], 422);
        }
    });
    DB::setDefaultConnection('d1');
    D1StagingRuntime::install();
}

it('rechecks queued reminder balances through the production D1 runtime', function (): void {
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
    DB::purge('sqlite');
    $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true, '--no-interaction' => true])->assertSuccessful();
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create(['expected_amount' => 4000]);
    $notification = new ContributionReminderNotification($contribution);
    Payment::factory()->forContribution($contribution)->create(['amount' => 1500]);
    $pdo = DB::connection('sqlite')->getPdo();
    connectProductionRuntimeFixture($pdo);
    $runtime = new D1StagingRuntime;

    $delivered = $runtime->run(function () use ($notification, $member): array {
        return [$notification->shouldSend($member, 'database'), $notification->toArray($member)['amount_owed']];
    });
    expect($delivered)->toBe([true, 2500]);

    $pdo->exec('update payments set amount = 4000 where contribution_id = '.$contribution->id);
    expect($runtime->run(fn (): bool => $notification->shouldSend($member, 'database')))->toBeFalse();
});

it('records one reviewed payment and receipt job when retried through the production runtime', function (): void {
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
    DB::purge('sqlite');
    $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true, '--no-interaction' => true])->assertSuccessful();
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $officer = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    Contribution::factory()->forUser($member)->currentMonth()->create(['expected_amount' => 4000]);
    $pdo = DB::connection('sqlite')->getPdo();
    connectProductionRuntimeFixture($pdo);
    $runtime = new D1StagingRuntime;
    $review = $runtime->run(function () use ($member, $family): array {
        $membership = $member->membershipForFamily($family);
        if (! $membership instanceof FamilyMembership) {
            throw new RuntimeException('The synthetic member has no family membership.');
        }

        return app(PaymentAllocationPreview::class)->preview($member, $family, $membership, 3000);
    });
    $key = (string) Str::uuid();
    $paidAt = now()->toDateString();
    $record = fn (int $amount, int $expiry) => $runtime->run(fn () => app(PaymentAllocationService::class)->createBatch(
        member: $member, amount: $amount, paidAt: $paidAt, recordedBy: $officer, family: $family,
        idempotencyKey: $key, expectedAllocations: $review['allocations'], reviewExpiresAt: $expiry,
    ));

    $first = $record(3000, now()->addMinute()->getTimestamp());
    $replay = $record(3000, now()->subMinute()->getTimestamp());
    expect($replay->id)->toBe($first->id);
    expect(fn () => $record(3500, now()->addMinute()->getTimestamp()))->toThrow(ValidationException::class);
    $counts = $pdo->query('select (select count(*) from payment_batches) as batches, (select count(*) from payments) as payments, (select sum(amount) from payments) as amount, (select count(*) from jobs) as jobs');
    if ($counts === false) {
        throw new RuntimeException('Unable to count fixture records.');
    }
    expect($counts->fetch(PDO::FETCH_ASSOC))->toBe(['batches' => 1, 'payments' => 1, 'amount' => 3000, 'jobs' => 1]);
});

function productionPaymentSqlValue(PDO $pdo, string $sql): mixed
{
    $statement = $pdo->query($sql);
    if ($statement === false) {
        throw new RuntimeException('Unable to query the synthetic production fixture.');
    }

    return $statement->fetchColumn();
}

it('records and audits a failed Paystack settlement through the D1 runtime', function (): void {
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
    DB::purge('sqlite');
    $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->assertSuccessful();
    $family = Family::factory()->create();
    $member = User::factory()->member()->create(['family_id' => $family->id]);
    $transaction = PaystackTransaction::query()->create([
        'reference' => 'd1-paystack-'.Str::uuid(), 'user_id' => $member->id, 'family_id' => $family->id,
        'type' => TransactionType::Contribution, 'amount' => 4000, 'gross_amount_kobo' => 400000,
        'status' => TransactionStatus::Initiated, 'fee_policy' => 'payer_pays',
    ]);
    $pdo = DB::connection('sqlite')->getPdo();
    connectProductionRuntimeFixture($pdo);
    $runtime = new D1StagingRuntime;

    expect(fn () => $runtime->run(fn () => app(PaystackContributionSettlementService::class)->settle(
        $transaction->reference, ['status' => 'success', 'amount' => 399900],
    ), $family->id))->toThrow(RuntimeException::class, 'does not match');

    expect($transaction->fresh()?->status)->toBe(TransactionStatus::Failed)
        ->and(productionPaymentSqlValue($pdo, "select count(*) from audit_events where action='paystack.state_changed'"))->toBe(1)
        ->and(productionPaymentSqlValue($pdo, 'select count(*) from payment_batches'))->toBe(0);
});

it('records a provider-reported failure once through the D1 runtime', function (): void {
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
    DB::purge('sqlite');
    $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->assertSuccessful();
    $family = Family::factory()->create();
    $member = User::factory()->member()->create(['family_id' => $family->id]);
    $transaction = PaystackTransaction::query()->create([
        'reference' => 'd1-provider-failure-'.Str::uuid(), 'user_id' => $member->id, 'family_id' => $family->id,
        'type' => TransactionType::Contribution, 'amount' => 4000, 'gross_amount_kobo' => 400000,
        'status' => TransactionStatus::Initiated, 'fee_policy' => 'payer_pays',
    ]);
    $pdo = DB::connection('sqlite')->getPdo();
    connectProductionRuntimeFixture($pdo);
    $runtime = new D1StagingRuntime;

    $runtime->run(fn () => app(PaystackContributionSettlementService::class)->recordProviderFailure($transaction->reference), $family->id);
    $runtime->run(fn () => app(PaystackContributionSettlementService::class)->recordProviderFailure($transaction->reference), $family->id);

    expect($transaction->fresh()?->status)->toBe(TransactionStatus::Failed)
        ->and(productionPaymentSqlValue($pdo, "select count(*) from audit_events where action='paystack.state_changed'"))->toBe(1)
        ->and(productionPaymentSqlValue($pdo, 'select count(*) from payment_batches'))->toBe(0);
});
