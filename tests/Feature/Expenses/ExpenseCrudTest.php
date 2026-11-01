<?php

declare(strict_types=1);

use App\Actions\ReverseExpense;
use App\Models\Expense;
use App\Models\Family;
use App\Models\FinancialReversal;
use App\Models\FundAdjustment;
use App\Models\PaymentBatch;
use App\Models\User;
use FamilyFunds\D1Staging\D1StagingSessionHandler;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->financialSecretary = User::factory()->financialSecretary()->create();
    $this->member = User::factory()->member()->employed()->create();
});

// =========================================================================
// Index
// =========================================================================

it('allows any authenticated user to view the expenses list', function () {
    $this->actingAs($this->member)
        ->get(route('expenses.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('Expenses/Index'));
});

it('returns paginated expenses with correct data', function () {
    Expense::factory()->count(3)->recordedBy($this->admin)->create();

    $this->actingAs($this->admin)
        ->get(route('expenses.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Expenses/Index')
            ->has('expenses.data', 3)
        );
});

it('returns expenses ordered by latest first', function () {
    Expense::factory()->spentOn('2026-01-01')->recordedBy($this->admin)->create(['description' => 'Older']);
    Expense::factory()->spentOn('2026-03-15')->recordedBy($this->admin)->create(['description' => 'Newer']);

    $this->actingAs($this->admin)
        ->get(route('expenses.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('expenses.data.0.description', 'Newer')
            ->where('expenses.data.1.description', 'Older')
        );
});

it('shows can_create as true for admin', function () {
    $this->actingAs($this->admin)
        ->get(route('expenses.index'))
        ->assertInertia(fn (Assert $page) => $page->where('can_create', true));
});

it('shows can_create as false for regular member', function () {
    $this->actingAs($this->member)
        ->get(route('expenses.index'))
        ->assertInertia(fn (Assert $page) => $page->where('can_create', false));
});

it('requires authentication to view expenses', function () {
    $this->get(route('expenses.index'))
        ->assertRedirect();
});

// =========================================================================
// Create
// =========================================================================

it('allows super admin to view create expense form', function () {
    $this->actingAs($this->admin)
        ->get(route('expenses.create'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('Expenses/Create'));
});

it('allows financial secretary to view create expense form', function () {
    $this->actingAs($this->financialSecretary)
        ->get(route('expenses.create'))
        ->assertSuccessful();
});

it('denies regular member access to create expense form', function () {
    $this->actingAs($this->member)
        ->get(route('expenses.create'))
        ->assertForbidden();
});

// =========================================================================
// Store
// =========================================================================

it('allows super admin to store an expense', function () {
    $this->actingAs($this->admin)
        ->post(route('expenses.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'amount' => 5000,
            'description' => 'Transport to meeting',
            'spent_at' => '2026-03-15',
        ])
        ->assertRedirect(route('expenses.index'));

    $expense = Expense::query()->firstOrFail();
    expect($expense->amount)->toBe(5000);
    expect($expense->description)->toBe('Transport to meeting');
    expect($expense->spent_at->toDateString())->toBe('2026-03-15');
    expect($expense->recorded_by)->toBe($this->admin->id);
});

it('allows financial secretary to store an expense', function () {
    $this->actingAs($this->financialSecretary)
        ->post(route('expenses.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'amount' => 3000,
            'description' => 'Phone calls',
            'spent_at' => '2026-03-14',
        ])
        ->assertRedirect(route('expenses.index'));

    expect(Expense::count())->toBe(1);
    expect(Expense::query()->firstOrFail()->recorded_by)->toBe($this->financialSecretary->id);
});

it('denies regular member from storing an expense', function () {
    $this->actingAs($this->member)
        ->post(route('expenses.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'amount' => 5000,
            'description' => 'Something',
            'spent_at' => '2026-03-15',
        ])
        ->assertForbidden();

    expect(Expense::count())->toBe(0);
});

it('validates required fields when storing', function (string $field) {
    $data = [
        'idempotency_key' => (string) Str::uuid(),
        'amount' => 5000,
        'description' => 'Transport',
        'spent_at' => '2026-03-15',
    ];

    unset($data[$field]);

    $this->actingAs($this->admin)
        ->post(route('expenses.store'), $data)
        ->assertSessionHasErrors($field);
})->with(['amount', 'description', 'spent_at']);

it('validates amount must be a positive integer', function () {
    $this->actingAs($this->admin)
        ->post(route('expenses.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'amount' => 0,
            'description' => 'Test',
            'spent_at' => '2026-03-15',
        ])
        ->assertSessionHasErrors('amount');
});

it('validates description max length', function () {
    $this->actingAs($this->admin)
        ->post(route('expenses.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'amount' => 5000,
            'description' => str_repeat('a', 1001),
            'spent_at' => '2026-03-15',
        ])
        ->assertSessionHasErrors('description');
});

it('requires a request key and rejects a reused key with different expense details', function () {
    $data = [
        'idempotency_key' => (string) Str::uuid(),
        'amount' => 5000,
        'description' => 'Transport',
        'spent_at' => '2026-03-15',
    ];
    $this->actingAs($this->admin)->post(route('expenses.store'), $data)->assertRedirect();
    $this->actingAs($this->admin)->post(route('expenses.store'), $data)->assertRedirect();
    $this->actingAs($this->admin)->post(route('expenses.store'), [...$data, 'amount' => 6000])
        ->assertSessionHasErrors('idempotency_key');
    $this->actingAs($this->admin)->post(route('expenses.store'), [...$data, 'idempotency_key' => null])
        ->assertSessionHasErrors('idempotency_key');
    expect(Expense::count())->toBe(1);
});

it('rejects reusing an expense request key for changed details or another officer', function (string $change) {
    $data = ['idempotency_key' => (string) Str::uuid(), 'amount' => 5000, 'description' => 'Transport', 'spent_at' => '2026-03-15'];
    $this->actingAs($this->admin)->post(route('expenses.store'), $data)->assertRedirect();
    $officer = $change === 'officer'
        ? User::factory()->financialSecretary()->create(['family_id' => $this->admin->family_id])
        : $this->admin;
    $changed = match ($change) {
        'description' => [...$data, 'description' => 'Different'],
        'date' => [...$data, 'spent_at' => '2026-03-16'],
        default => $data,
    };
    $this->actingAs($officer)->post(route('expenses.store'), $changed)->assertSessionHasErrors('idempotency_key');
    expect(Expense::count())->toBe(1);
})->with(['description', 'date', 'officer']);

it('permits separate expenses with distinct request keys', function () {
    $data = ['idempotency_key' => (string) Str::uuid(), 'amount' => 5000, 'description' => 'Transport', 'spent_at' => '2026-03-15'];
    $this->actingAs($this->admin)->post(route('expenses.store'), $data)->assertRedirect();
    $this->actingAs($this->admin)->post(route('expenses.store'), [...$data, 'idempotency_key' => (string) Str::uuid()])->assertRedirect();
    expect(Expense::count())->toBe(2);
});

it('reports an earlier reversal when an expense request is replayed', function () {
    $data = ['idempotency_key' => (string) Str::uuid(), 'amount' => 5000, 'description' => 'Transport', 'spent_at' => '2026-03-15'];
    $this->actingAs($this->admin)->post(route('expenses.store'), $data)->assertRedirect();
    app(ReverseExpense::class)->handle(Expense::sole(), $this->admin, 'Synthetic reversal');
    $this->actingAs($this->admin)->post(route('expenses.store'), $data)
        ->assertRedirect()->assertSessionHas('success', 'This expense was already recorded and later reversed.');
    expect(Expense::count())->toBe(1);
});

it('does not duplicate an expense after a session-save conflict', function (): void {
    require_once __DIR__.'/../../../deployment/d1-proof/autoload.php';
    $remote = new PDO('sqlite::memory:');
    $remote->exec('create table sessions(id text primary key,payload text,last_activity integer)');
    $injectConflict = true;
    Http::preventStrayRequests();
    Http::fake(function (ClientRequest $request) use ($remote, &$injectConflict): PromiseInterface {
        if ($request->url() !== 'https://familyfunds-d1-staging-sql.familyfunds-lab.workers.dev/query') {
            throw new RuntimeException('Unexpected synthetic session endpoint.');
        }
        $sql = $request['sql'];
        $params = $request['params'];
        if (! is_string($sql) || ! is_array($params)) {
            throw new RuntimeException('Invalid synthetic session request.');
        }
        if ($injectConflict && Expense::query()->exists() && str_starts_with($sql, 'update sessions set payload=')) {
            $remote->exec("update sessions set payload='Y29uZmxpY3Q='");
            $injectConflict = false;
        }
        $statement = $remote->prepare($sql);
        $statement->execute($params);

        return Http::response(['database' => 'familyfunds-d1-web-staging', 'result' => ['results' => $statement->fetchAll(PDO::FETCH_ASSOC)]]);
    });
    config(['d1-staging.enabled' => true, 'session.driver' => 'expense-conflict-session', 'session.encrypt' => true, 'session.lottery' => [0, 100]]);
    app('session')->extend('expense-conflict-session', fn (): D1StagingSessionHandler => new D1StagingSessionHandler(
        'https://familyfunds-d1-staging-sql.familyfunds-lab.workers.dev', 'synthetic-token', 'familyfunds-d1-web-staging', 120,
    ));
    $data = ['idempotency_key' => (string) Str::uuid(), 'amount' => 5000, 'description' => 'Transport', 'spent_at' => '2026-03-15'];
    $this->actingAs($this->admin)->post(route('expenses.store'), $data)->assertInternalServerError();
    expect($injectConflict)->toBeFalse()->and(Expense::count())->toBe(1);
    $this->actingAs($this->admin)->post(route('expenses.store'), $data)->assertRedirect();
    expect(Expense::count())->toBe(1);
});

// =========================================================================
// Reverse
// =========================================================================

it('allows super admin to reverse an expense without deleting it', function () {
    $expense = Expense::factory()->recordedBy($this->admin)->create();

    $this->actingAs($this->admin)
        ->post(route('expenses.reverse', $expense), ['reason' => 'Duplicate expense'])
        ->assertRedirect();

    expect(Expense::count())->toBe(1)
        ->and($expense->reversal()->exists())->toBeTrue();
});

it('does not duplicate a financial reversal after a session-save conflict', function (string $kind): void {
    require_once __DIR__.'/../../../deployment/d1-proof/autoload.php';
    [$record, $routeName, $routeParameter] = match ($kind) {
        'expense' => [Expense::factory()->recordedBy($this->admin)->create(), 'expenses.reverse', 'expense'],
        'adjustment' => [FundAdjustment::factory()->recordedBy($this->admin)->create(), 'fund-adjustments.reverse', 'fund_adjustment'],
        'payment' => [PaymentBatch::factory()->create(['family_id' => $this->admin->family_id, 'recorded_by' => $this->admin->id]), 'payment-batches.reverse', 'payment_batch'],
        default => throw new InvalidArgumentException('Unknown financial reversal type.'),
    };
    $family = $this->admin->family;
    if (! $family instanceof Family) {
        throw new RuntimeException('The test administrator needs a family.');
    }
    $remote = new PDO('sqlite::memory:');
    $remote->exec('create table sessions(id text primary key,payload text,last_activity integer)');
    $injectConflict = true;
    Http::preventStrayRequests();
    Http::fake(function (ClientRequest $request) use ($remote, &$injectConflict): PromiseInterface {
        if ($request->url() !== 'https://familyfunds-d1-staging-sql.familyfunds-lab.workers.dev/query') {
            throw new RuntimeException('Unexpected synthetic session endpoint.');
        }
        $sql = $request['sql'];
        $params = $request['params'];
        if (! is_string($sql) || ! is_array($params)) {
            throw new RuntimeException('Invalid synthetic session request.');
        }
        if ($injectConflict && FinancialReversal::query()->exists() && str_starts_with($sql, 'update sessions set payload=')) {
            $remote->exec("update sessions set payload='Y29uZmxpY3Q='");
            $injectConflict = false;
        }
        $statement = $remote->prepare($sql);
        $statement->execute($params);

        return Http::response(['database' => 'familyfunds-d1-web-staging', 'result' => ['results' => $statement->fetchAll(PDO::FETCH_ASSOC)]]);
    });
    config(['d1-staging.enabled' => true, 'session.driver' => 'expense-reversal-conflict-session', 'session.encrypt' => true, 'session.lottery' => [0, 100]]);
    app('session')->extend('expense-reversal-conflict-session', fn (): D1StagingSessionHandler => new D1StagingSessionHandler(
        'https://familyfunds-d1-staging-sql.familyfunds-lab.workers.dev', 'synthetic-token', 'familyfunds-d1-web-staging', 120,
    ));
    $url = route($routeName, ['current_family' => $family->slug, $routeParameter => $record]);
    $data = ['reason' => 'Synthetic duplicate'];
    $this->actingAs($this->admin)->post($url, $data)->assertInternalServerError();
    expect($injectConflict)->toBeFalse()->and(FinancialReversal::count())->toBe(1);
    $this->actingAs($this->admin)->post($url, $data)->assertRedirect();
    expect(FinancialReversal::count())->toBe(1)->and($record->fresh()?->isReversed())->toBeTrue();
    if ($kind === 'payment') {
        $this->actingAs($this->admin)
            ->get(route('payments.index', ['current_family' => $family->slug]))
            ->assertInertia(fn (Assert $page) => $page->where('receipts.0.can_reverse', false));
        $secretary = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
        $this->actingAs($secretary)->post($url, $data)->assertForbidden();
        $outsider = User::factory()->admin()->create();
        $this->actingAs($outsider)->post($url, $data)->assertForbidden();
        expect(FinancialReversal::count())->toBe(1);
    }
})->with(['expense', 'adjustment', 'payment']);

it('denies financial secretary from reversing an expense', function () {
    $expense = Expense::factory()->recordedBy($this->financialSecretary)->create();

    $this->actingAs($this->financialSecretary)
        ->post(route('expenses.reverse', $expense), ['reason' => 'Duplicate expense'])
        ->assertForbidden();

    expect(Expense::count())->toBe(1);
});

it('denies regular member from reversing an expense', function () {
    $expense = Expense::factory()->recordedBy($this->admin)->create();

    $this->actingAs($this->member)
        ->post(route('expenses.reverse', $expense), ['reason' => 'Duplicate expense'])
        ->assertForbidden();

    expect(Expense::count())->toBe(1);
});
