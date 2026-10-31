<?php

declare(strict_types=1);

use App\Actions\ReversePaymentBatch;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Jobs\ProcessWhatsAppWebhook;
use App\Jobs\SendPaymentReceipt;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\PaystackTransaction;
use App\Models\User;
use App\Services\PaymentAllocationPreview;
use App\Services\PaymentAllocationService;
use App\Services\PaystackContributionSettlementService;
use FamilyFunds\D1Staging\D1StagingConnection;
use FamilyFunds\D1Staging\D1StagingConnector;
use FamilyFunds\D1Staging\D1StagingRuntime;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

pest()->extend(TestCase::class);

beforeEach(function (): void {
    require_once __DIR__.'/../../deployment/d1-proof/autoload.php';
    config(['d1-staging.enabled' => true, 'd1-staging.planning' => false, 'd1-staging.read_snapshot_cache' => false]);
});

afterEach(function (): void {
    if (config('d1-staging.read_snapshot_cache')) {
        File::deleteDirectory(Config::string('d1-staging.read_snapshot_path'));
    }
});

/** @param (Closure(PDO, list<array{sql: string, params: list<scalar|null>}>): void)|null $afterBatch */
function stagingReadCacheFixture(?Closure $afterBatch = null): PDO
{
    $pdo = stagingSqlFixture($afterBatch);
    $pdo->exec('create table "users" ("id" integer primary key, "family_id" integer, "role" text, foreign key("family_id") references "families"("id"))');
    $pdo->exec('create table "family_members" ("id" integer primary key, "family_id" integer, "user_id" integer, "role" text, foreign key("family_id") references "families"("id"), foreign key("user_id") references "users"("id"))');
    $pdo->exec("insert into users values(10,1,'member'),(11,1,'admin'),(20,2,'member')");
    $pdo->exec("insert into family_members values(1,1,10,'member'),(2,1,11,'admin'),(3,2,20,'member')");
    config([
        'd1-staging.database' => 'familyfunds-d1-web-staging',
        'd1-staging.read_snapshot_cache' => true,
        'd1-staging.read_snapshot_path' => storage_path('framework/testing/d1-cache-'.Str::uuid()),
    ]);
    D1StagingRuntime::installReadCache();

    return $pdo;
}

it('handles another request creating the private read cache directory first', function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('The deterministic race test requires pcntl.');
    }

    stagingReadCacheFixture();
    $pid = pcntl_fork();
    if ($pid === 0) {
        eval('namespace FamilyFunds\\D1Staging; function mkdir($path, $mode, $recursive) { \\mkdir($path, $mode, $recursive); return \\mkdir($path, $mode, $recursive); }');
        try {
            $result = (new D1StagingRuntime)->runReadOnly(fn () => DB::table('contributions')->sum('amount'), 1, 10);
            exit($result === 10 ? 0 : 1);
        } catch (Throwable) {
            exit(1);
        }
    }
    expect($pid)->toBeGreaterThan(0);
    pcntl_waitpid($pid, $status);
    if (! is_int($status)) {
        throw new RuntimeException('Could not read the race test process status.');
    }
    expect(pcntl_wifexited($status))->toBeTrue()
        ->and(pcntl_wexitstatus($status))->toBe(0);
});

it('fails closed when the private read cache directory cannot be created', function (): void {
    stagingReadCacheFixture();
    $directory = Config::string('d1-staging.read_snapshot_path');
    file_put_contents($directory, 'not a directory');
    try {
        expect(fn () => (new D1StagingRuntime)->runReadOnly(fn () => null, 1, 10))
            ->toThrow(RuntimeException::class, 'Could not prepare the private staging read cache.');
    } finally {
        unlink($directory);
    }
});

it('shares a read snapshot between members while reading fresh committed amounts and roles', function (): void {
    $pdo = stagingReadCacheFixture();
    $runtime = new D1StagingRuntime;
    expect($runtime->runReadOnly(fn () => DB::table('contributions')->sum('amount'), 1, 10))->toBe(10);
    $requests = count(Http::recorded());
    expect($runtime->runReadOnly(fn () => DB::table('contributions')->sum('amount'), 1, 11))->toBe(10)
        ->and(count(Http::recorded()) - $requests)->toBe(1);

    $pdo->exec('update contributions set amount=70 where id=1');
    expect($runtime->runReadOnly(fn () => DB::table('contributions')->sum('amount'), 1, 11))->toBe(70);
    $pdo->exec("update family_members set role='member' where user_id=11");
    expect($runtime->runReadOnly(fn () => DB::table('family_members')->where('user_id', 11)->value('role'), 1, 11))->toBe('member');
});

it('limits receipt delivery snapshots to the selected family', function (): void {
    $pdo = stagingReadCacheFixture();
    $pdo->exec('create table "payment_batches" ("id" integer primary key, "family_id" integer, foreign key("family_id") references "families"("id"))');
    $pdo->exec('create table "payment_receipt_deliveries" ("id" integer primary key, "payment_batch_id" integer, "status" text, foreign key("payment_batch_id") references "payment_batches"("id"))');
    $pdo->exec('insert into payment_batches values (1,1),(2,2)');
    $pdo->exec("insert into payment_receipt_deliveries values (1,1,'queued'),(2,2,'accepted')");
    D1StagingRuntime::installReadCache();

    $statuses = (new D1StagingRuntime)->runReadOnly(
        fn () => DB::table('payment_receipt_deliveries')->pluck('status')->all(), 1, 10,
    );

    expect($statuses)->toBe(['queued']);
});

it('installs identifier reservations and read invalidation for new delivery tables', function (): void {
    $pdo = stagingSqlFixture();
    $pdo->exec('create table payment_batches (id integer primary key autoincrement, family_id integer not null references families(id))');
    $pdo->exec('create table payment_receipt_deliveries (id integer primary key autoincrement, payment_batch_id integer not null references payment_batches(id))');
    $pdo->exec('create table contribution_reminder_deliveries (id integer primary key autoincrement, contribution_id integer not null references contributions(id))');
    D1StagingRuntime::install();
    D1StagingRuntime::installReadCache();

    expect(stagingSqlValue($pdo, "select count(*) from d1_staging_sequences where name in ('payment_receipt_deliveries','contribution_reminder_deliveries')"))->toBe(2)
        ->and(stagingSqlValue($pdo, "select count(*) from sqlite_master where type='trigger' and (name glob 'd1_read_revision_payment_receipt_deliveries_*' or name glob 'd1_read_revision_contribution_reminder_deliveries_*')"))->toBe(6);

    $before = stagingSqlValue($pdo, 'select revision from d1_staging_read_revision where id=1');
    if (! is_int($before)) {
        throw new RuntimeException('Invalid read-cache revision');
    }
    $pdo->exec('insert into payment_batches (id,family_id) values (1,1)');
    $pdo->exec('insert into payment_receipt_deliveries (payment_batch_id) values (1)');
    $pdo->exec('insert into contribution_reminder_deliveries (contribution_id) values (1)');
    expect(stagingSqlValue($pdo, 'select revision from d1_staging_read_revision where id=1'))->toBe($before + 3);
});

it('captures a cold read with two remote batches and still sees the next committed amount', function (): void {
    $pdo = stagingReadCacheFixture();
    $runtime = new D1StagingRuntime;
    $before = count(Http::recorded());
    expect($runtime->runReadOnly(fn () => DB::table('contributions')->sum('amount'), 1, 10))->toBe(10)
        ->and(count(Http::recorded()) - $before)->toBe(2);
    $pdo->exec('update contributions set amount=90 where id=1');
    expect($runtime->runReadOnly(fn () => DB::table('contributions')->sum('amount'), 1, 10))->toBe(90)
        ->and(stagingSqlValue($pdo, 'select count(*) from d1_staging_leases'))->toBe(0);
});

it('serves an atomic read during continuous commits and invalidates it on the next request', function (): void {
    $pdo = stagingReadCacheFixture(function (PDO $remote, array $queries): void {
        if (array_any($queries, fn (array $query): bool => str_starts_with($query['sql'], 'select * from "contributions"'))) {
            $remote->exec('update contributions set amount=amount+1 where id=1');
        }
    });
    $runtime = new D1StagingRuntime;
    expect($runtime->runReadOnly(fn () => DB::table('contributions')->sum('amount'), 1, 10))->toBe(10)
        ->and($runtime->runReadOnly(fn () => DB::table('contributions')->sum('amount'), 1, 11))->toBe(11)
        ->and(stagingSqlValue($pdo, 'select amount from contributions where id=1'))->toBe(12)
        ->and(stagingSqlValue($pdo, 'select count(*) from d1_staging_leases'))->toBe(0);
});

it('uses a read-only lease if related parents cannot be captured consistently during writes', function (): void {
    $pdo = stagingReadCacheFixture(function (PDO $remote, array $queries): void {
        if (array_any($queries, fn (array $query): bool => str_starts_with($query['sql'], 'select * from "contributions"'))
            && stagingSqlValue($remote, "select count(*) from d1_staging_leases where scope='global'") === 0) {
            $remote->exec('update contributions set amount=amount+1 where id=1');
        }
    });
    $pdo->exec('create table "audit_entries" ("id" integer primary key, "family_id" integer, "actor_id" integer, foreign key("family_id") references "families"("id"), foreign key("actor_id") references "users"("id"))');
    $pdo->exec('insert into audit_entries values(1,1,20)');
    D1StagingRuntime::installReadCache();
    $result = (new D1StagingRuntime)->runReadOnly(function (): int {
        expect(fn () => DB::table('contributions')->update(['amount' => 999]))->toThrow(QueryException::class);

        return (int) DB::table('contributions')->sum('amount');
    }, 1, 10);

    expect($result)->toBe(13)
        ->and(stagingSqlValue($pdo, 'select amount from contributions where id=1'))->toBe(13)
        ->and(stagingSqlValue($pdo, 'select count(*) from d1_staging_leases'))->toBe(0);
});

it('isolates cached families and refuses writes even on a warm snapshot', function (): void {
    stagingReadCacheFixture();
    $runtime = new D1StagingRuntime;
    expect($runtime->runReadOnly(fn () => DB::table('contributions')->pluck('family_id')->all(), 1, 10))->toBe([1])
        ->and($runtime->runReadOnly(fn () => DB::table('contributions')->pluck('family_id')->all(), 2, 20))->toBe([2]);
    expect(fn () => $runtime->runReadOnly(fn () => DB::table('contributions')->update(['amount' => 999]), 1, 11))
        ->toThrow(QueryException::class);
    expect(DB::getDefaultConnection())->toBe('d1-staging')
        ->and(config('d1-staging.planning'))->toBeFalse();
});

it('rebuilds cached schema and global feature values after they change', function (): void {
    $pdo = stagingReadCacheFixture();
    $runtime = new D1StagingRuntime;
    $runtime->runReadOnly(fn () => DB::table('contributions')->count(), 1, 10);
    $pdo->exec('alter table contributions add column memo text');
    $pdo->exec("update contributions set memo='fresh' where id=1");
    expect($runtime->runReadOnly(fn () => DB::table('contributions')->where('id', 1)->value('memo'), 1, 10))->toBe('fresh');
    $pdo->exec('create table features (id integer primary key, value text)');
    D1StagingRuntime::installReadCache();
    $pdo->exec("insert into features values(1,'disabled')");
    expect($runtime->runReadOnly(fn () => DB::table('features')->value('value'), 1, 10))->toBe('disabled');
    $pdo->exec("update features set value='enabled'");
    expect($runtime->runReadOnly(fn () => DB::table('features')->value('value'), 1, 10))->toBe('enabled');
});

it('does not reuse a member cache for an actor outside that family', function (): void {
    stagingReadCacheFixture();
    $runtime = new D1StagingRuntime;
    $runtime->runReadOnly(fn () => DB::table('users')->pluck('id')->all(), 1, 10);
    expect($runtime->runReadOnly(fn () => DB::table('users')->orderBy('id')->pluck('id')->all(), 1, 20))->toBe([10, 11, 20]);
});

it('recovers a damaged disposable cache and fails closed when schema trigger coverage is missing', function (): void {
    $pdo = stagingReadCacheFixture();
    $runtime = new D1StagingRuntime;
    $runtime->runReadOnly(fn () => DB::table('contributions')->count(), 1, 10);
    $files = File::glob(Config::string('d1-staging.read_snapshot_path').'/*.sqlite');
    expect($files)->toHaveCount(1);
    foreach ($files as $file) {
        if (! is_string($file)) {
            throw new RuntimeException('Invalid staging cache fixture path.');
        }
        file_put_contents($file, 'interrupted cache');
    }
    expect($runtime->runReadOnly(fn () => DB::table('contributions')->sum('amount'), 1, 10))->toBe(10);
    $pdo->exec('drop trigger d1_read_revision_contributions_update');
    expect(fn () => $runtime->runReadOnly(fn () => DB::table('contributions')->sum('amount'), 1, 10))
        ->toThrow(RuntimeException::class, 'revision triggers');
});

it('refuses a database outside the isolated staging allowlist', function (): void {
    expect(fn () => new D1StagingConnection('https://production.invalid', 'synthetic-token', 'production'))
        ->toThrow(RuntimeException::class, 'explicitly isolated');
});

it('prevents a model from bypassing the atomic request plan', function (): void {
    Http::fake();
    $connection = new D1StagingConnection(D1StagingConnection::TARGETS['familyfunds-d1-web-staging'], 'synthetic-token', 'familyfunds-d1-web-staging');
    config(['d1-staging.planning' => true]);
    expect(fn () => $connection->statement('delete from payments'))
        ->toThrow(RuntimeException::class, 'planning');
    Http::assertNothingSent();
});

it('rejects a response from a different database', function (): void {
    Http::fake(['*' => Http::response(['database' => 'wrong-database', 'result' => ['results' => [], 'meta' => ['changes' => 0]]])]);
    $connection = new D1StagingConnection(D1StagingConnection::TARGETS['familyfunds-d1-web-staging'], 'synthetic-token', 'familyfunds-d1-web-staging');
    expect(fn () => $connection->select('select 1'))
        ->toThrow(RuntimeException::class, 'unexpected database');
});

/** @param (Closure(PDO, list<array{sql: string, params: list<scalar|null>}>): void)|null $afterBatch */
function stagingSqlFixture(?Closure $afterBatch = null): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('pragma foreign_keys=on');
    $pdo->exec('create table jobs (id integer primary key autoincrement, lease_token text)');
    $pdo->exec('create table "families" ("id" integer primary key autoincrement, "name" text not null)');
    $pdo->exec('create table "contributions" ("id" integer primary key autoincrement, "family_id" integer not null references families(id), "amount" integer not null)');
    $pdo->exec("insert into families values (1,'First'),(2,'Second')");
    $pdo->exec('insert into contributions values(1,1,10),(2,2,20)');
    connectStagingSqlFixture($pdo, $afterBatch);

    return $pdo;
}

/** @param (Closure(PDO, list<array{sql: string, params: list<scalar|null>}>): void)|null $afterBatch */
function connectStagingSqlFixture(PDO $pdo, ?Closure $afterBatch = null): void
{
    Http::fake(function (Request $request) use ($pdo, $afterBatch): PromiseInterface {
        $body = $request->data();
        /** @var list<array{sql: string, params: list<scalar|null>}> $batch */
        $batch = $body['batch'] ?? [$body];
        $results = [];
        $pdo->beginTransaction();
        try {
            foreach ($batch as $query) {
                $statement = $pdo->prepare($query['sql']);
                $statement->execute($query['params']);
                $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
                $results[] = ['results' => $rows, 'meta' => ['changes' => stagingSqlValue($pdo, 'select changes()')]];
            }
            $pdo->commit();
            if ($afterBatch !== null) {
                $afterBatch($pdo, $batch);
            }

            return Http::response(['database' => 'familyfunds-d1-web-staging', 'result' => isset($body['batch']) ? $results : $results[0]]);
        } catch (Throwable $error) {
            $pdo->rollBack();

            return Http::response(['error' => $error->getMessage()], 422);
        }
    });
    DB::extend('d1-staging', fn (): D1StagingConnection => new D1StagingConnection(D1StagingConnection::TARGETS['familyfunds-d1-web-staging'], 'synthetic-token', 'familyfunds-d1-web-staging'));
    config(['database.connections.d1-staging' => ['driver' => 'd1-staging'], 'database.default' => 'd1-staging']);
    D1StagingRuntime::install();

}

it('isolates a family snapshot and reserves globally distinct identifiers', function (): void {
    $pdo = stagingSqlFixture();
    $runtime = new D1StagingRuntime;
    $ids = [];
    foreach ([1, 2] as $family) {
        $ids[] = $runtime->run(function () use ($family): int {
            expect(DB::table('contributions')->pluck('family_id')->unique()->all())->toBe([$family]);

            return DB::table('contributions')->insertGetId(['family_id' => $family, 'amount' => 30]);
        }, $family);
    }
    expect(count(array_unique($ids)))->toBe(2)
        ->and(stagingSqlValue($pdo, 'select count(*) from contributions'))->toBe(4)
        ->and(stagingSqlValue($pdo, 'select count(*) from d1_staging_leases'))->toBe(0);
});

it('keeps a nested queue insert and reminder marker together when the transaction fails', function (): void {
    $pdo = stagingSqlFixture();
    $runtime = new D1StagingRuntime;
    $runtime->run(function (): void {
        try {
            DB::transaction(function (): void {
                DB::table('jobs')->insert(['lease_token' => 'reminder-job']);
                DB::table('contributions')->where('id', 1)->update(['amount' => 99]);
                throw new RuntimeException('Simulated interruption');
            });
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->toBe('Simulated interruption');
        }
    }, 1);
    expect(stagingSqlValue($pdo, 'select count(*) from jobs'))->toBe(0)
        ->and(stagingSqlValue($pdo, 'select amount from contributions where id=1'))->toBe(10);
});

it('commits a receipt job and financial write together through the D1 queue', function (): void {
    $pdo = stagingSqlFixture();
    foreach (['queue text', 'payload text', 'attempts integer', 'reserved_at integer', 'available_at integer', 'created_at integer'] as $column) {
        $pdo->exec('alter table jobs add column '.$column);
    }
    config(['queue.default' => 'd1-staging', 'queue.connections.d1-staging' => ['driver' => 'd1-staging', 'queue' => 'default']]);
    Queue::extend('d1-staging', fn (): D1StagingConnector => new D1StagingConnector);
    $runtime = new D1StagingRuntime;

    $runtime->run(function (): void {
        try {
            DB::transaction(function (): void {
                DB::table('contributions')->where('id', 1)->update(['amount' => 99]);
                SendPaymentReceipt::dispatch(1, 10, 'mail');
                throw new RuntimeException('Simulated interruption');
            });
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->toBe('Simulated interruption');
        }
    }, 1);
    expect(stagingSqlValue($pdo, 'select count(*) from jobs'))->toBe(0)
        ->and(stagingSqlValue($pdo, 'select amount from contributions where id=1'))->toBe(10);

    $runtime->run(function (): void {
        DB::transaction(function (): void {
            DB::table('contributions')->where('id', 1)->update(['amount' => 99]);
            SendPaymentReceipt::dispatch(1, 10, 'mail');
        });
    }, 1);
    expect(stagingSqlValue($pdo, 'select count(*) from jobs'))->toBe(1)
        ->and(stagingSqlValue($pdo, 'select amount from contributions where id=1'))->toBe(99);
});

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
    connectStagingSqlFixture($pdo);
    $runtime = new D1StagingRuntime;

    expect(fn () => $runtime->run(fn () => app(PaystackContributionSettlementService::class)->settle(
        $transaction->reference, ['status' => 'success', 'amount' => 399900],
    ), $family->id))->toThrow(RuntimeException::class, 'does not match');

    expect($transaction->fresh()?->status)->toBe(TransactionStatus::Failed)
        ->and(stagingSqlValue($pdo, "select count(*) from audit_events where action='paystack.state_changed'"))->toBe(1)
        ->and(stagingSqlValue($pdo, 'select count(*) from payment_batches'))->toBe(0);
});

it('allocates payments and queues receipts through the D1 runtime using current balances', function (): void {
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
    DB::purge('sqlite');
    $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->assertSuccessful();
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $officer = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create(['expected_amount' => 4000]);
    $pdo = DB::connection('sqlite')->getPdo();
    connectStagingSqlFixture($pdo);
    config(['queue.default' => 'd1-staging', 'queue.connections.d1-staging' => ['driver' => 'd1-staging', 'queue' => 'default']]);
    Queue::extend('d1-staging', fn (): D1StagingConnector => new D1StagingConnector);
    $runtime = new D1StagingRuntime;

    $firstReview = $runtime->run(function () use ($member, $family): array {
        $membership = $member->membershipForFamily($family);
        if (! $membership instanceof FamilyMembership) {
            throw new RuntimeException('The synthetic member has no family membership.');
        }

        return app(PaymentAllocationPreview::class)->preview($member, $family, $membership, 3000);
    }, $family->id, $officer->id);
    $first = $runtime->run(fn () => app(PaymentAllocationService::class)->createBatch(
        member: $member, amount: 3000, paidAt: now(), recordedBy: $officer, family: $family, idempotencyKey: 'd1-first',
        expectedAllocations: $firstReview['allocations'], reviewExpiresAt: now()->addMinute()->getTimestamp(),
    ), $family->id, $officer->id);
    $replayed = $runtime->run(fn () => app(PaymentAllocationService::class)->createBatch(
        member: $member, amount: 3000, paidAt: now(), recordedBy: $officer, family: $family, idempotencyKey: 'd1-first',
        expectedAllocations: $firstReview['allocations'], reviewExpiresAt: now()->subMinute()->getTimestamp(),
    ), $family->id, $officer->id);
    expect($replayed->id)->toBe($first->id)
        ->and(stagingSqlValue($pdo, 'select count(*) from jobs'))->toBe(1);
    expect(fn () => $runtime->run(fn () => app(PaymentAllocationService::class)->createBatch(
        member: $member, amount: 3000, paidAt: now(), recordedBy: $officer, family: $family, idempotencyKey: 'd1-expired-new',
        expectedAllocations: $firstReview['allocations'], reviewExpiresAt: now()->subMinute()->getTimestamp(),
    ), $family->id, $officer->id))->toThrow(ValidationException::class);
    expect(stagingSqlValue($pdo, 'select count(*) from payment_batches'))->toBe(1)
        ->and(stagingSqlValue($pdo, 'select count(*) from jobs'))->toBe(1);
    $review = $runtime->run(function () use ($member, $family): array {
        $membership = $member->membershipForFamily($family);
        if (! $membership instanceof FamilyMembership) {
            throw new RuntimeException('The synthetic member has no family membership.');
        }

        return app(PaymentAllocationPreview::class)->preview($member, $family, $membership, 2000);
    }, $family->id, $officer->id);
    $runtime->run(fn () => app(PaymentAllocationService::class)->createBatch(
        member: $member, amount: 2000, paidAt: now(), recordedBy: $officer, family: $family, idempotencyKey: 'd1-second',
    ), $family->id, $officer->id);

    expect(stagingSqlValue($pdo, 'select sum(amount) from payments where contribution_id='.$contribution->id))->toBe(4000)
        ->and(stagingSqlValue($pdo, 'select sum(amount) from payments'))->toBe(5000)
        ->and(stagingSqlValue($pdo, 'select count(*) from payment_batches'))->toBe(2)
        ->and(stagingSqlValue($pdo, 'select count(*) from jobs'))->toBe(2);

    expect(fn () => $runtime->run(fn () => app(PaymentAllocationService::class)->createBatch(
        member: $member, amount: 2000, paidAt: now(), recordedBy: $officer, family: $family,
        idempotencyKey: 'd1-stale-review', expectedAllocations: $review['allocations'],
    ), $family->id, $officer->id))->toThrow(ValidationException::class);
    expect(stagingSqlValue($pdo, 'select count(*) from payment_batches'))->toBe(2)
        ->and(stagingSqlValue($pdo, 'select count(*) from jobs'))->toBe(2);

    $runtime->run(fn () => app(ReversePaymentBatch::class)->handle($first, $officer, 'Synthetic reversal'), $family->id, $officer->id);
    $runtime->run(fn () => app(PaymentAllocationService::class)->createBatch(
        member: $member, amount: 1000, paidAt: now(), recordedBy: $officer, family: $family, idempotencyKey: 'd1-third',
    ), $family->id, $officer->id);

    expect(stagingSqlValue($pdo, 'select sum(payments.amount) from payments where contribution_id='.$contribution->id." and not exists (select 1 from financial_reversals where reversible_type='payment_batch' and reversible_id=payments.payment_batch_id)"))->toBe(2000)
        ->and(stagingSqlValue($pdo, 'select count(*) from financial_reversals'))->toBe(1)
        ->and(stagingSqlValue($pdo, 'select count(*) from payment_batches'))->toBe(3);
});

it('preserves a read WhatsApp receipt when an older callback runs through D1', function (): void {
    $pdo = stagingSqlFixture();
    $pdo->exec('create table whatsapp_messages (id integer primary key autoincrement, wa_message_id text, status text, error_code text, error_message text, read_at text, created_at text, updated_at text)');
    $pdo->exec("insert into whatsapp_messages (wa_message_id,status) values ('wamid.fixture','sent')");
    D1StagingRuntime::install();
    $runtime = new D1StagingRuntime;
    $runtime->run(function (): void {
        (new ProcessWhatsAppWebhook(['entry' => [['changes' => [[
            'field' => 'messages', 'value' => ['statuses' => [['id' => 'wamid.fixture', 'status' => 'read']]],
        ]]]]]))->handle();
    });
    expect(stagingSqlValue($pdo, "select status from whatsapp_messages where wa_message_id='wamid.fixture'"))->toBe('read');
    $runtime->run(function (): void {
        (new ProcessWhatsAppWebhook(['entry' => [['changes' => [[
            'field' => 'messages', 'value' => ['statuses' => [['id' => 'wamid.fixture', 'status' => 'sent']]],
        ]]]]]))->handle();
    });

    expect(stagingSqlValue($pdo, "select status from whatsapp_messages where wa_message_id='wamid.fixture'"))->toBe('read');
});

it('queues an early WhatsApp status retry through the D1 runtime', function (): void {
    $pdo = stagingSqlFixture();
    foreach (['queue text', 'payload text', 'attempts integer', 'reserved_at integer', 'available_at integer', 'created_at integer'] as $column) {
        $pdo->exec('alter table jobs add column '.$column);
    }
    $pdo->exec('create table whatsapp_messages (id integer primary key autoincrement, wa_message_id text, status text, error_code text, error_message text, read_at text, created_at text, updated_at text)');
    D1StagingRuntime::install();
    config(['queue.default' => 'd1-staging', 'queue.connections.d1-staging' => ['driver' => 'd1-staging', 'queue' => 'default']]);
    Queue::extend('d1-staging', fn (): D1StagingConnector => new D1StagingConnector);

    (new D1StagingRuntime)->run(function (): void {
        (new ProcessWhatsAppWebhook(['entry' => [['changes' => [[
            'field' => 'messages', 'value' => ['statuses' => [['id' => 'wamid.early', 'status' => 'delivered']]],
        ]]]]]))->handle();
    });

    expect(stagingSqlValue($pdo, 'select count(*) from jobs'))->toBe(1);
});

it('fences an expired owner without deleting its replacement lease', function (): void {
    $pdo = stagingSqlFixture();
    $runtime = new D1StagingRuntime;
    expect(fn () => $runtime->run(function () use ($pdo): void {
        DB::table('contributions')->where('id', 1)->update(['amount' => 99]);
        $pdo->exec("update d1_staging_leases set token='replacement',expires_at=unixepoch()+120");
    }, 1))->toThrow(RuntimeException::class, 'd1_staging_fresh_snapshot');
    expect(stagingSqlValue($pdo, 'select amount from contributions where id=1'))->toBe(10)
        ->and(stagingSqlValue($pdo, 'select token from d1_staging_leases'))->toBe('replacement');
});

it('keeps leased requests within four remote calls while reading current schema and committing once', function (): void {
    $pdo = stagingSqlFixture();
    $runtime = new D1StagingRuntime;
    $before = count(Http::recorded());
    $runtime->run(fn (): int => DB::table('contributions')->where('id', 1)->update(['amount' => 11]), 1);
    expect(count(Http::recorded()) - $before)->toBe(4)
        ->and(stagingSqlValue($pdo, 'select amount from contributions where id=1'))->toBe(11);
    $pdo->exec('alter table contributions add column note text');
    $before = count(Http::recorded());
    $runtime->run(fn (): int => DB::table('contributions')->where('id', 1)->update(['note' => 'current schema']), 1);
    expect(count(Http::recorded()) - $before)->toBe(4)
        ->and(stagingSqlValue($pdo, 'select note from contributions where id=1'))->toBe('current schema')
        ->and(stagingSqlValue($pdo, 'select count(*) from d1_staging_leases'))->toBe(0);
});

it('retains the authenticated actor without importing another family ledger', function (): void {
    $pdo = stagingSqlFixture();
    $pdo->exec('create table "users" ("id" integer primary key, "family_id" integer references families(id), "name" text)');
    $pdo->exec('create table "family_members" ("id" integer primary key, "family_id" integer references families(id), "user_id" integer references users(id))');
    $pdo->exec("insert into users values (10,1,'Actor'),(11,1,'Unrelated'),(20,2,'Target')");
    $pdo->exec('insert into family_members values (1,1,10),(2,1,11),(3,2,20)');
    (new D1StagingRuntime)->run(function (): void {
        expect(DB::table('users')->orderBy('id')->pluck('id')->all())->toBe([10, 20])
            ->and(DB::table('family_members')->orderBy('user_id')->pluck('user_id')->all())->toBe([10, 20])
            ->and(DB::table('contributions')->pluck('family_id')->all())->toBe([2]);
        DB::table('users')->where('id', 10)->update(['name' => 'Actor retained']);
    }, 2, 10);
    expect(stagingSqlValue($pdo, 'select name from users where id=10'))->toBe('Actor retained');
});

it('allows separate families while excluding overlapping global work', function (): void {
    stagingSqlFixture();
    $remote = DB::connection('d1-staging');
    $statements = [
        D1StagingRuntime::leaseStatement('family:1', 'one'),
        D1StagingRuntime::leaseStatement('family:2', 'two'),
        D1StagingRuntime::leaseStatement('family:1', 'duplicate'),
        D1StagingRuntime::leaseStatement('global', 'global'),
    ];
    assert($remote instanceof D1StagingConnection);
    $results = $remote->batch($statements);
    expect($results[0]['results'])->toHaveCount(1)
        ->and($results[1]['results'])->toHaveCount(1)
        ->and($results[2]['results'])->toBe([])
        ->and($results[3]['results'])->toBe([]);
});

it('commits a valid allocation larger than the former forty-two-write cap atomically', function (): void {
    $pdo = stagingSqlFixture();
    $runtime = new D1StagingRuntime;
    $runtime->run(function (): void {
        for ($index = 0; $index < 60; $index++) {
            DB::table('contributions')->insert(['family_id' => 1, 'amount' => 1]);
        }
    }, 1);
    expect(stagingSqlValue($pdo, 'select count(*) from contributions where family_id=1'))->toBe(61);
});

it('reads a committed family snapshot without taking a write lease or reserving identifiers', function (): void {
    $pdo = stagingSqlFixture();
    $pdo->exec("insert into d1_staging_leases values('family:1','active-writer',unixepoch()+120)");
    $sequenceSql = "select json_group_array(json_object('name',name,'next_id',next_id)) from (select * from d1_staging_sequences order by name)";
    $sequences = stagingSqlValue($pdo, $sequenceSql);

    $amount = (new D1StagingRuntime)->runReadOnly(fn (): int => (int) DB::table('contributions')->sum('amount'), 1, 10);

    expect($amount)->toBe(10)
        ->and(stagingSqlValue($pdo, $sequenceSql))->toBe($sequences)
        ->and(stagingSqlValue($pdo, 'select token from d1_staging_leases'))->toBe('active-writer');
});

it('rejects a write from a read-only callback and restores the native connection', function (): void {
    $pdo = stagingSqlFixture();

    expect(fn () => (new D1StagingRuntime)->runReadOnly(
        fn (): int => DB::table('contributions')->where('id', 1)->update(['amount' => 99]), 1, 10,
    ))->toThrow(QueryException::class, 'readonly');

    expect(stagingSqlValue($pdo, 'select amount from contributions where id=1'))->toBe(10)
        ->and(DB::getDefaultConnection())->toBe('d1-staging')
        ->and(config('d1-staging.planning'))->toBeFalse();
});

it('refreshes read-only data after a committed financial change', function (): void {
    $pdo = stagingSqlFixture();
    $runtime = new D1StagingRuntime;
    $read = fn (): int => $runtime->runReadOnly(fn (): int => (int) DB::table('contributions')->sum('amount'), 1, 10);

    expect($read())->toBe(10);
    $pdo->exec('update contributions set amount=25 where id=1');
    expect($read())->toBe(25);
});

function stagingSqlValue(PDO $pdo, string $sql): mixed
{
    $statement = $pdo->query($sql);
    if ($statement === false) {
        throw new RuntimeException('Test fixture query failed.');
    }

    return $statement->fetchColumn();
}
