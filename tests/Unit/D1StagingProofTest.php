<?php

declare(strict_types=1);

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\AuditEvent;
use App\Models\Contribution;
use App\Models\Expense;
use App\Models\Family;
use App\Models\Payment;
use App\Models\PaymentBatch;
use App\Models\PaystackTransaction;
use App\Models\ReconciliationPeriod;
use App\Models\User;
use App\Support\EffectiveLedger;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\D1CommandPlan;
use Tests\Support\D1FinancialCommands;
use Tests\Support\D1ProofConnection;
use Tests\Support\D1ProofConnector;
use Tests\Support\D1ProofPaymentJob;
use Tests\Support\D1ProofQueue;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    if (! config('d1-proof.enabled')) {
        $this->markTestSkipped('Opt-in live D1 proof; production and existing staging are never targets.');
    }

    Http::preventStrayRequests();
    Http::allowStrayRequests([d1Setting('url').'/*']);
    config(['mail.default' => 'array', 'cache.default' => 'array', 'queue.default' => 'sync']);
    DB::extend('d1-proof', fn (): D1ProofConnection => new D1ProofConnection(d1Setting('url'), d1Setting('token')));
    config(['database.connections.d1-proof' => ['driver' => 'd1-proof', 'database' => 'familyfunds-d1-staging-proof']]);
    config(['database.default' => 'd1-proof']);

    if (d1Remote()->integerValue("select count(*) from sqlite_master where name = 'd1_proof_metadata'")) {
        d1InstallCommands();

        return;
    }

    config([
        'database.connections.proof-schema' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true],
        'database.default' => 'proof-schema',
    ]);
    $migrationExit = Artisan::call('migrate', ['--database' => 'proof-schema', '--force' => true, '--no-interaction' => true]);
    if ($migrationExit !== 0) {
        throw new RuntimeException('Synthetic schema preparation failed: '.Artisan::output());
    }
    /** @var list<object{sql: string}> $schema */
    $schema = DB::connection('proof-schema')->select("select sql from sqlite_master where sql is not null and name not like 'sqlite_%' order by case type when 'table' then 0 else 1 end, name");
    config(['database.default' => 'd1-proof']);
    $queries = array_map(fn ($row): array => [
        'sql' => preg_replace('/^CREATE (TABLE|UNIQUE INDEX|INDEX) /i', 'CREATE $1 IF NOT EXISTS ', $row->sql) ?? throw new RuntimeException('Invalid schema DDL'),
        'params' => [],
    ], $schema);
    foreach (array_chunk($queries, 20) as $chunk) {
        d1Remote()->batch($chunk);
    }
    DB::statement('create table if not exists d1_proof_counters (id text primary key, balance integer not null check (balance >= 0))');
    DB::statement('create table d1_proof_metadata (source_commit text not null)');
    DB::insert('insert into d1_proof_metadata values (?)', ['510955317bb4da09b9a9d7808b0e9d1c65c55b00']);
    d1InstallCommands();
});

/** @return array{Family, User, User, Contribution} */
function d1FinancialFixture(): array
{
    $family = Family::factory()->create(['name' => 'Synthetic D1 Proof', 'currency' => 'NGN']);
    $actor = User::factory()->withoutTwoFactor()->financialSecretary()->create(['family_id' => $family->id, 'name' => 'Synthetic Secretary']);
    $member = User::factory()->withoutTwoFactor()->member()->employed()->create(['family_id' => $family->id, 'name' => 'Synthetic Member']);
    $contribution = Contribution::factory()->create([
        'family_id' => $family->id, 'user_id' => $member->id, 'expected_amount' => 4000,
        'year' => now()->year, 'month' => now()->month,
    ]);

    return [$family, $actor, $member, $contribution];
}

it('imports the real application schema into isolated remote D1', function (): void {
    $tables = array_column(DB::select("select name from sqlite_master where type = 'table'"), 'name');
    expect($tables)->toContain('families', 'users', 'payments', 'payment_batches', 'paystack_transactions', 'jobs', 'sessions', 'cache');
});

it('round trips synthetic Eloquent models through remote D1', function (): void {
    [$family, $actor, $member, $contribution] = d1FinancialFixture();
    expect($family->refresh()->name)->toBe('Synthetic D1 Proof')
        ->and($member->refresh()->family_id)->toBe($family->id)
        ->and($contribution->refresh()->expected_amount)->toBe(4000);
});

it('rolls back a failed native D1 batch without partial writes', function (): void {
    $id = (string) Str::uuid();
    DB::insert('insert into d1_proof_counters values (?, ?)', [$id, 10]);
    expect(fn () => d1Remote()->batch([
        ['sql' => 'update d1_proof_counters set balance = 20 where id = ?', 'params' => [$id]],
        ['sql' => 'update d1_proof_counters set balance = -1 where id = ?', 'params' => [$id]],
    ]))->toThrow(RuntimeException::class, 'CHECK constraint failed');
    expect(d1Remote()->integerValue('select balance from d1_proof_counters where id = ?', [$id]))->toBe(10);
});

it('preserves one available unit under twenty simultaneous conditional D1 updates', function (): void {
    $id = (string) Str::uuid();
    DB::insert('insert into d1_proof_counters values (?, ?)', [$id, 1]);
    $responses = d1Responses(Http::pool(fn (Pool $pool): array => array_map(
        fn (int $index) => $pool->withToken(d1Setting('token'))->timeout(30)->post(d1Setting('url').'/query', [
            'sql' => 'update d1_proof_counters set balance = balance - 1 where id = ? and balance > 0 returning balance',
            'params' => [$id],
        ]),
        range(1, 20),
    )));
    $successfulClaims = 0;
    foreach ($responses as $response) {
        expect($response->successful())->toBeTrue();
        $successfulClaims += count(d1ResultRows($response));
    }
    expect($successfulClaims)->toBe(1)
        ->and(d1Remote()->integerValue('select balance from d1_proof_counters where id = ?', [$id]))->toBe(0);
});

it('rejects unsupported interactive transactions before executing their writes', function (): void {
    $id = (string) Str::uuid();
    DB::insert('insert into d1_proof_counters values (?, ?)', [$id, 10]);
    expect(fn () => DB::transaction(fn (): int => DB::update('update d1_proof_counters set balance = 20 where id = ?', [$id])))
        ->toThrow(RuntimeException::class);
    expect(d1Remote()->integerValue('select balance from d1_proof_counters where id = ?', [$id]))->toBe(10);
});

it('GATE records a real FamilyFunds payment and deduplicates its idempotency key', function (): void {
    [$family, $actor, $member, $contribution] = d1FinancialFixture();
    $key = (string) Str::uuid();
    $service = app(D1FinancialCommands::class);
    $arguments = d1AllocationArguments($family, $actor, $member, 4000, $key);
    $first = $service->execute('allocate', $arguments);
    $second = $service->execute('allocate', $arguments);
    expect($second->getKey())->toBe($first->getKey())
        ->and((int) Payment::where('contribution_id', $contribution->id)->sum('amount'))->toBe(4000);
});

it('GATE settles duplicate verified Paystack notifications exactly once', function (): void {
    [$family, $actor, $member, $contribution] = d1FinancialFixture();
    $transaction = PaystackTransaction::create([
        'reference' => 'D1-PROOF-'.Str::uuid(), 'user_id' => $member->id, 'family_id' => $family->id,
        'type' => TransactionType::Contribution, 'amount' => 4000, 'gross_amount_kobo' => 400000,
        'status' => TransactionStatus::Pending,
    ]);
    $data = ['amount' => 400000, 'fees' => 0, 'status' => 'success', 'paid_at' => now()->toDateString()];
    $service = app(D1FinancialCommands::class);
    $arguments = ['reference' => $transaction->reference, 'provider_data' => $data];
    $first = $service->execute('settle', $arguments);
    $second = $service->execute('settle', $arguments);
    assert($first instanceof PaystackTransaction && $second instanceof PaystackTransaction);
    expect($second->payment_batch_id)->toBe($first->payment_batch_id)
        ->and((int) Payment::where('contribution_id', $contribution->id)->sum('amount'))->toBe(4000);
});

it('GATE reverses a payment batch exactly once', function (): void {
    [$family, $actor, $member] = d1FinancialFixture();
    $batch = PaymentBatch::factory()->create(['family_id' => $family->id, 'recorded_by' => $actor->id]);
    $action = app(D1FinancialCommands::class);
    $arguments = ['batch_id' => $batch->id, 'actor_id' => $actor->id, 'reason' => 'Synthetic D1 proof reversal'];
    $first = $action->execute('reverse', $arguments);
    $second = $action->execute('reverse', $arguments);
    expect($second->getKey())->toBe($first->getKey());
});

it('GATE closes a reconciliation period with an atomic ledger snapshot', function (): void {
    [$family, $actor] = d1FinancialFixture();
    $period = ReconciliationPeriod::factory()->create(['family_id' => $family->id]);
    $closed = app(D1FinancialCommands::class)->execute('close', ['period_id' => $period->id, 'actor_id' => $actor->id]);
    assert($closed instanceof ReconciliationPeriod);
    expect($closed->closed_at)->not->toBeNull();
});

it('GATE claims and acknowledges a real Laravel database queue job', function (): void {
    $queue = d1Queue();
    $id = $queue->pushRaw('{"displayName":"SyntheticD1Proof","job":"SyntheticOnly","data":{}}');
    $job = $queue->pop();
    expect($job)->not->toBeNull();
    assert($job !== null);
    $job->delete();
    expect(DB::table('jobs')->where('id', $id)->exists())->toBeFalse();
});

function d1InstallCommands(): void
{
    static $installed = false;
    if ($installed) {
        return;
    }
    $columns = array_column(DB::select('pragma table_info(jobs)'), 'name');
    if (! in_array('lease_token', $columns, true)) {
        DB::statement('alter table jobs add column lease_token text');
    }
    D1FinancialCommands::install();
    $installed = true;
}

/** @return array{family_id: int, actor_id: int, member_id: int, amount: int, paid_at: string, key: string} */
function d1AllocationArguments(Family $family, User $actor, User $member, int $amount = 4000, ?string $key = null): array
{
    return ['family_id' => $family->id, 'actor_id' => $actor->id, 'member_id' => $member->id,
        'amount' => $amount, 'paid_at' => now()->toDateString(), 'key' => $key ?? (string) Str::uuid()];
}

function d1Queue(): D1ProofQueue
{
    Queue::extend('d1-proof', fn (): D1ProofConnector => new D1ProofConnector);
    config(['queue.connections.d1-proof' => ['driver' => 'd1-proof', 'queue' => 'd1-proof-'.Str::uuid()]]);
    $queue = Queue::connection('d1-proof');
    assert($queue instanceof D1ProofQueue);

    return $queue;
}

/**
 * @param  list<array{sql: string, params: list<scalar|null>}>  $batch
 * @return list<Response>
 */
function d1ConcurrentBatches(array $batch, int $count = 20): array
{
    return d1Responses(Http::pool(fn (Pool $pool): array => array_map(
        fn (int $index) => $pool->withToken(d1Setting('token'))->timeout(30)->post(d1Setting('url').'/batch', ['batch' => $batch]),
        range(1, $count),
    )));
}

it('commits only one of twenty simultaneous payment plans and retries without overallocating', function (): void {
    [$family, $actor, $member, $contribution] = d1FinancialFixture();
    $commands = app(D1FinancialCommands::class);
    $first = d1AllocationArguments($family, $actor, $member);
    $second = d1AllocationArguments($family, $actor, $member);
    $plans = [$commands->plan('allocate', $first), $commands->plan('allocate', $second)];
    $batches = array_map($commands->batch(...), $plans);
    $responses = d1Responses(Http::pool(fn (Pool $pool): array => array_map(
        fn (int $index) => $pool->withToken(d1Setting('token'))->timeout(30)->post(d1Setting('url').'/batch', ['batch' => $batches[$index % 2]]),
        range(0, 19),
    )));
    $committed = 0;
    foreach ($responses as $response) {
        if ($response->successful()) {
            $committed++;
        } else {
            expect($response->status())->toBe(422)
                ->and($response->json('error'))->toContain('d1_proof_fresh_snapshot');
        }
    }
    expect($committed)->toBe(1);
    $commands->execute('allocate', $first);
    $commands->execute('allocate', $second);
    expect(PaymentBatch::where('family_id', $family->id)->count())->toBe(2)
        ->and(PaymentBatch::where('family_id', $family->id)->distinct()->count('receipt_number'))->toBe(2)
        ->and((int) Payment::where('contribution_id', $contribution->id)->sum('amount'))->toBe(4000)
        ->and((int) PaymentBatch::where('family_id', $family->id)->sum('total_amount'))->toBe(8000)
        ->and((int) Payment::whereIn('contribution_id', Contribution::where('family_id', $family->id)->select('id'))->sum('amount'))->toBe(8000);
});

it('settles twenty duplicate Paystack commands with one allocation and one fee expense', function (): void {
    [$family, $actor, $member, $contribution] = d1FinancialFixture();
    $transaction = PaystackTransaction::create([
        'reference' => 'D1-PROOF-'.Str::uuid(), 'user_id' => $member->id, 'family_id' => $family->id,
        'type' => TransactionType::Contribution, 'amount' => 4000, 'gross_amount_kobo' => 400000,
        'status' => TransactionStatus::Pending,
    ]);
    $arguments = ['reference' => $transaction->reference, 'provider_data' => [
        'amount' => 400000, 'fees' => 10000, 'status' => 'success', 'paid_at' => now()->toDateString(),
    ]];
    $commands = app(D1FinancialCommands::class);
    $plan = $commands->plan('settle', $arguments);
    expect($plan->error)->toBeNull();
    $responses = d1ConcurrentBatches($commands->batch($plan));
    expect(count(array_filter($responses, fn (Response $response): bool => $response->successful())))->toBe(1);
    foreach ($responses as $response) {
        if (! $response->successful()) {
            expect($response->status())->toBe(422)->and($response->json('error'))->toContain('d1_proof_fresh_snapshot');
        }
    }
    $commands->execute('settle', $arguments);
    $settled = $transaction->refresh();
    assert($settled->feeExpense instanceof Expense);
    expect($settled->status)->toBe(TransactionStatus::Allocated)
        ->and($settled->fee_expense_id)->not->toBeNull()
        ->and((int) $settled->feeExpense->amount)->toBe(100)
        ->and(PaymentBatch::where('family_id', $family->id)->count())->toBe(1)
        ->and(Expense::where('family_id', $family->id)->count())->toBe(1)
        ->and((int) Payment::where('contribution_id', $contribution->id)->sum('amount'))->toBe(4000);
});

it('rolls back financial rows fees audit and epoch when the final D1 statement fails', function (): void {
    [$family, $actor, $member] = d1FinancialFixture();
    $transaction = PaystackTransaction::create([
        'reference' => 'D1-PROOF-'.Str::uuid(), 'user_id' => $member->id, 'family_id' => $family->id,
        'type' => TransactionType::Contribution, 'amount' => 4000, 'gross_amount_kobo' => 400000,
        'status' => TransactionStatus::Pending,
    ]);
    $commands = app(D1FinancialCommands::class);
    $plan = $commands->plan('settle', ['reference' => $transaction->reference, 'provider_data' => [
        'amount' => 400000, 'fees' => 10000, 'status' => 'success',
    ]]);
    expect($plan->error)->toBeNull();
    $auditCount = AuditEvent::where('family_id', $family->id)->count();
    $injected = new D1CommandPlan($plan->revision, [...$plan->queries, [
        'sql' => 'insert into d1_proof_counters (id, balance) values (?, -1)', 'params' => [(string) Str::uuid()],
    ]], $plan->modelClass, $plan->modelId);
    expect(fn () => $commands->commit($injected))->toThrow(RuntimeException::class, 'CHECK constraint failed');
    expect($transaction->refresh()->status)->toBe(TransactionStatus::Pending)
        ->and(PaymentBatch::where('family_id', $family->id)->count())->toBe(0)
        ->and(Expense::where('family_id', $family->id)->count())->toBe(0)
        ->and(AuditEvent::where('family_id', $family->id)->count())->toBe($auditCount)
        ->and(d1Remote()->integerValue('select revision from d1_proof_epoch where id = 1'))->toBe($plan->revision)
        ->and(DB::table('d1_proof_assertions')->count())->toBe(0);
});

it('rejects an advance-limit violation without leaving receipts payments contributions or audit', function (): void {
    [$family, $actor, $member] = d1FinancialFixture();
    $commands = app(D1FinancialCommands::class);
    expect(fn () => $commands->execute('allocate', d1AllocationArguments($family, $actor, $member, 1000000)))
        ->toThrow(InvalidArgumentException::class, 'six-month');
    expect(PaymentBatch::where('family_id', $family->id)->count())->toBe(0)
        ->and(Contribution::where('family_id', $family->id)->count())->toBe(1)
        ->and(AuditEvent::where('family_id', $family->id)->where('action', 'payment.posted')->count())->toBe(0);
});

it('persists a failed Paystack verification without creating financial allocations', function (): void {
    [$family, $actor, $member] = d1FinancialFixture();
    $transaction = PaystackTransaction::create([
        'reference' => 'D1-PROOF-'.Str::uuid(), 'user_id' => $member->id, 'family_id' => $family->id,
        'type' => TransactionType::Contribution, 'amount' => 4000, 'gross_amount_kobo' => 400000,
        'status' => TransactionStatus::Pending,
    ]);
    expect(fn () => app(D1FinancialCommands::class)->execute('settle', [
        'reference' => $transaction->reference, 'provider_data' => ['amount' => 1, 'status' => 'success'],
    ]))->toThrow(RuntimeException::class, 'does not match');
    expect($transaction->refresh()->status)->toBe(TransactionStatus::Failed)
        ->and(PaymentBatch::where('family_id', $family->id)->count())->toBe(0);
});

it('rejects a stale close plan and recomputes its ledger snapshot after a payment commits', function (): void {
    [$family, $actor, $member] = d1FinancialFixture();
    $period = ReconciliationPeriod::factory()->create(['family_id' => $family->id]);
    $commands = app(D1FinancialCommands::class);
    $arguments = ['period_id' => $period->id, 'actor_id' => $actor->id];
    $plan = $commands->plan('close', $arguments);
    $commands->execute('allocate', d1AllocationArguments($family, $actor, $member));
    expect(fn () => $commands->commit($plan))->toThrow(RuntimeException::class, 'd1_proof_fresh_snapshot');
    expect($period->refresh()->closed_at)->toBeNull();
    $closed = $commands->execute('close', $arguments);
    assert($closed instanceof ReconciliationPeriod);
    expect((int) $closed->ledger_net)->toBe(4000)->and((int) $closed->closing_balance)->toBe(4000)
        ->and($closed->closed_at)->not->toBeNull();
    expect(fn () => $commands->execute('allocate', d1AllocationArguments($family, $actor, $member)))
        ->toThrow(InvalidArgumentException::class);
});

it('invalidates a plan when a read dependency changes outside the command gateway', function (): void {
    [$family, $actor, $member] = d1FinancialFixture();
    $commands = app(D1FinancialCommands::class);
    $plan = $commands->plan('allocate', d1AllocationArguments($family, $actor, $member));
    $family->update(['name' => 'Synthetic concurrent family change']);
    expect(fn () => $commands->commit($plan))->toThrow(RuntimeException::class, 'd1_proof_fresh_snapshot');
    expect(PaymentBatch::where('family_id', $family->id)->count())->toBe(0);
});

it('claims a single job exactly once across twenty simultaneous workers', function (): void {
    $queue = d1Queue();
    $id = $queue->pushRaw('{"displayName":"SyntheticD1Proof","job":"SyntheticOnly","data":{}}');
    $responses = d1Responses(Http::pool(fn (Pool $pool): array => array_map(
        fn (int $index) => $pool->withToken(d1Setting('token'))->timeout(30)->post(d1Setting('url').'/query', D1ProofQueue::claimStatement($queue->getQueue(null), 90, (string) Str::uuid())),
        range(1, 20),
    )));
    $claims = [];
    foreach ($responses as $response) {
        expect($response->successful())->toBeTrue();
        $claims = [...$claims, ...d1ResultRows($response)];
    }
    expect($claims)->toHaveCount(1)->and($claims[0]['id'])->toBe($id)->and($claims[0]['attempts'])->toBe(1);
    DB::table('jobs')->where('id', $id)->delete();
});

it('fences expired workers from acknowledging or releasing a reclaimed job', function (): void {
    $queue = d1Queue();
    $id = $queue->pushRaw('{"displayName":"SyntheticD1Proof","job":"SyntheticOnly","data":{}}');
    $old = $queue->pop();
    assert($old !== null);
    expect($old)->not->toBeNull()->and($queue->pop())->toBeNull();
    DB::update('update jobs set reserved_at = unixepoch() - 91 where id = ?', [$id]);
    $current = $queue->pop();
    assert($current !== null);
    expect($current)->not->toBeNull()->and($current->attempts())->toBe(2)
        ->and($current->leaseToken())->not->toBe($old->leaseToken());
    $old->delete();
    $old->release();
    expect(DB::table('jobs')->where('id', $id)->value('lease_token'))->toBe($current->leaseToken());
    $current->release(60);
    expect($queue->pop())->toBeNull()->and(DB::table('jobs')->where('id', $id)->value('reserved_at'))->toBeNull();
    DB::update('update jobs set available_at = unixepoch() - 1 where id = ?', [$id]);
    $retry = $queue->pop();
    assert($retry !== null);
    expect($retry->attempts())->toBe(3);
    $retry->delete();
    expect(DB::table('jobs')->where('id', $id)->exists())->toBeFalse();
});

it('executes a real serialized Laravel payment job and safely handles crash-before-ack redelivery', function (): void {
    [$family, $actor, $member, $contribution] = d1FinancialFixture();
    $queue = d1Queue();
    $arguments = d1AllocationArguments($family, $actor, $member);
    $id = Queue::connection('d1-proof')->push(new D1ProofPaymentJob($arguments));
    $job = $queue->pop();
    expect($job)->not->toBeNull();
    app(D1FinancialCommands::class)->execute('allocate', $arguments);
    DB::update('update jobs set reserved_at = unixepoch() - 91 where id = ?', [$id]);
    $redelivered = $queue->pop();
    assert($redelivered !== null);
    $redelivered->fire();
    expect($redelivered->isDeleted())->toBeTrue()
        ->and(DB::table('jobs')->where('id', $id)->exists())->toBeFalse()
        ->and(PaymentBatch::where('family_id', $family->id)->count())->toBe(1)
        ->and((int) Payment::where('contribution_id', $contribution->id)->sum('amount'))->toBe(4000);
});

function d1Setting(string $key): string
{
    $value = config('d1-proof.'.$key);
    if (! is_string($value)) {
        throw new RuntimeException('Invalid proof setting.');
    }

    return $value;
}

function d1Remote(): D1ProofConnection
{
    $remote = DB::connection('d1-proof');
    assert($remote instanceof D1ProofConnection);

    return $remote;
}

/**
 * @param  array<array-key, Response|Throwable>  $responses
 * @return list<Response>
 */
function d1Responses(array $responses): array
{
    $validated = [];
    foreach ($responses as $response) {
        if (! $response instanceof Response) {
            throw new RuntimeException('Concurrent D1 transport failed.', previous: $response);
        }
        $validated[] = $response;
    }

    return $validated;
}

/** @return list<array<string, mixed>> */
function d1ResultRows(Response $response): array
{
    $rows = $response->json('result.results');
    if (! is_array($rows) || ! array_is_list($rows)) {
        throw new RuntimeException('Invalid concurrent D1 result.');
    }
    $validated = [];
    foreach ($rows as $row) {
        if (! is_array($row)) {
            throw new RuntimeException('Invalid concurrent D1 row.');
        }
        $validatedRow = [];
        foreach ($row as $key => $value) {
            if (! is_string($key)) {
                throw new RuntimeException('Invalid concurrent D1 column.');
            }
            $validatedRow[$key] = $value;
        }
        $validated[] = $validatedRow;
    }

    return $validated;
}

it('allocates exactly six months ahead in one batch and preserves every allocation total', function (): void {
    [$family, $actor, $member] = d1FinancialFixture();
    $batch = app(D1FinancialCommands::class)->execute('allocate', d1AllocationArguments($family, $actor, $member, 28000));
    assert($batch instanceof PaymentBatch);
    $contributions = Contribution::where('family_id', $family->id)->orderBy('year')->orderBy('month')->get();
    expect($contributions)->toHaveCount(7)
        ->and((int) $batch->allocations()->sum('amount'))->toBe(28000)
        ->and($batch->allocations()->count())->toBe(7)
        ->and($contributions->last()?->month)->toBe(now()->addMonths(6)->month);
});

it('refuses a financial batch if its revision guard row is missing', function (): void {
    [$family, $actor, $member] = d1FinancialFixture();
    $commands = app(D1FinancialCommands::class);
    $plan = $commands->plan('allocate', d1AllocationArguments($family, $actor, $member));
    DB::delete('delete from d1_proof_epoch where id = 1');
    try {
        expect(fn () => $commands->commit($plan))->toThrow(RuntimeException::class, 'd1_proof_fresh_snapshot');
        expect(PaymentBatch::where('family_id', $family->id)->count())->toBe(0);
    } finally {
        DB::insert('insert into d1_proof_epoch values (1, ?)', [$plan->revision + 1]);
    }
});

it('leaves an expired unclaimed job available for recovery when its old worker acknowledges', function (): void {
    $queue = d1Queue();
    $id = $queue->pushRaw('{"displayName":"SyntheticD1Proof","job":"SyntheticOnly","data":{}}');
    $old = $queue->pop();
    assert($old !== null);
    DB::update('update jobs set reserved_at = unixepoch() - 91 where id = ?', [$id]);
    $old->delete();
    $old->release(60);
    expect(DB::table('jobs')->where('id', $id)->exists())->toBeTrue();
    $current = $queue->pop();
    assert($current !== null);
    expect($current->attempts())->toBe(2);
    $current->delete();
    expect(DB::table('jobs')->where('id', $id)->exists())->toBeFalse();
});

it('claims only eligible jobs in queue order while leaving delayed and other queues untouched', function (): void {
    $queue = d1Queue();
    $other = $queue->pushRaw('{"job":"SyntheticOnly"}', 'another-'.Str::uuid());
    $delayed = $queue->pushRaw('{"job":"SyntheticOnly"}');
    DB::update('update jobs set available_at = unixepoch() + 60 where id = ?', [$delayed]);
    $first = $queue->pushRaw('{"job":"SyntheticOnly"}');
    $second = $queue->pushRaw('{"job":"SyntheticOnly"}');
    $one = $queue->pop();
    $two = $queue->pop();
    assert($one !== null && $two !== null);
    expect($one->getJobId())->toBe($first)->and($two->getJobId())->toBe($second)->and($queue->pop())->toBeNull();
    $one->delete();
    $two->delete();
    expect(DB::table('jobs')->whereIn('id', [$other, $delayed])->count())->toBe(2);
    DB::table('jobs')->whereIn('id', [$other, $delayed])->delete();
});

it('reverses an allocated receipt once while preserving its audit history and effective ledger', function (): void {
    [$family, $actor, $member] = d1FinancialFixture();
    $commands = app(D1FinancialCommands::class);
    $batch = $commands->execute('allocate', d1AllocationArguments($family, $actor, $member));
    $arguments = ['batch_id' => $batch->getKey(), 'actor_id' => $actor->id, 'reason' => 'Synthetic allocated receipt reversal'];
    $first = $commands->execute('reverse', $arguments);
    $second = $commands->execute('reverse', $arguments);
    $ledger = app(EffectiveLedger::class);
    expect($second->getKey())->toBe($first->getKey())
        ->and((int) $ledger->paymentsForFamily($family->id)->sum('amount'))->toBe(0)
        ->and((int) Payment::where('payment_batch_id', $batch->getKey())->sum('amount'))->toBe(4000)
        ->and(AuditEvent::where('family_id', $family->id)->where('action', 'financial.reversed')->count())->toBe(1);
});
