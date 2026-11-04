<?php

declare(strict_types=1);

use App\Actions\ReverseFundAdjustment;
use App\Models\Family;
use App\Models\FundAdjustment;
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

it('allows any authenticated user to view fund adjustments', function () {
    $this->actingAs($this->member)
        ->get(route('fund-adjustments.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('FundAdjustments/Index'));
});

it('returns paginated adjustments with correct data', function () {
    FundAdjustment::factory()->count(3)->recordedBy($this->admin)->create();

    $this->actingAs($this->admin)
        ->get(route('fund-adjustments.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('FundAdjustments/Index')
            ->has('adjustments.data', 3)
        );
});

it('shows can_create as true for super admin', function () {
    $this->actingAs($this->admin)
        ->get(route('fund-adjustments.index'))
        ->assertInertia(fn (Assert $page) => $page->where('can_create', true));
});

it('shows can_create as true for financial secretary', function () {
    $this->actingAs($this->financialSecretary)
        ->get(route('fund-adjustments.index'))
        ->assertInertia(fn (Assert $page) => $page->where('can_create', true));
});

it('shows can_create as false for regular member', function () {
    $this->actingAs($this->member)
        ->get(route('fund-adjustments.index'))
        ->assertInertia(fn (Assert $page) => $page->where('can_create', false));
});

// =========================================================================
// Store
// =========================================================================

it('allows super admin to store a fund adjustment', function () {
    $this->actingAs($this->admin)
        ->post(route('fund-adjustments.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'amount' => 200000,
            'description' => 'Opening balance from 2+ years of contributions',
            'recorded_at' => '2026-03-15',
        ])
        ->assertRedirect(route('fund-adjustments.index'));

    $adjustment = FundAdjustment::query()->firstOrFail();
    expect($adjustment->amount)->toBe(200000);
    expect($adjustment->description)->toBe('Opening balance from 2+ years of contributions');
    expect($adjustment->recorded_at->toDateString())->toBe('2026-03-15');
    expect($adjustment->recorded_by)->toBe($this->admin->id);
});

it('allows financial secretary to store a fund adjustment', function () {
    $this->actingAs($this->financialSecretary)
        ->post(route('fund-adjustments.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'amount' => 200000,
            'description' => 'Opening balance',
            'recorded_at' => '2026-03-15',
        ])
        ->assertRedirect(route('fund-adjustments.index'));

    $adjustment = FundAdjustment::query()->firstOrFail();
    expect($adjustment->recorded_by)->toBe($this->financialSecretary->id);
});

it('uses the family currency in the fund adjustment recorded flash message', function () {
    $family = Family::factory()->create(['currency' => 'QAR']);
    $financialSecretary = User::factory()->financialSecretary()->create([
        'family_id' => $family->id,
    ]);

    $this->actingAs($financialSecretary)
        ->post(route('fund-adjustments.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'amount' => 450,
            'description' => 'Opening balance',
            'recorded_at' => '2026-06-08',
        ])
        ->assertRedirect(route('fund-adjustments.index'))
        ->assertSessionHas('success', 'Fund adjustment of QAR 450.00 recorded successfully.');
});

it('denies regular member from storing a fund adjustment', function () {
    $this->actingAs($this->member)
        ->post(route('fund-adjustments.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'amount' => 200000,
            'description' => 'Opening balance',
            'recorded_at' => '2026-03-15',
        ])
        ->assertForbidden();

    expect(FundAdjustment::count())->toBe(0);
});

it('validates required fields when storing', function (string $field) {
    $data = [
        'idempotency_key' => (string) Str::uuid(),
        'amount' => 200000,
        'description' => 'Opening balance',
        'recorded_at' => '2026-03-15',
    ];

    unset($data[$field]);

    $this->actingAs($this->admin)
        ->post(route('fund-adjustments.store'), $data)
        ->assertSessionHasErrors($field);
})->with(['amount', 'description', 'recorded_at']);

it('validates amount must be a positive integer', function () {
    $this->actingAs($this->admin)
        ->post(route('fund-adjustments.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'amount' => 0,
            'description' => 'Test',
            'recorded_at' => '2026-03-15',
        ])
        ->assertSessionHasErrors('amount');
});

it('records the same adjustment request once and requires a key', function (): void {
    $data = ['idempotency_key' => (string) Str::uuid(), 'amount' => -5000, 'description' => 'Correction', 'recorded_at' => '2026-03-15'];
    $this->actingAs($this->admin)->post(route('fund-adjustments.store'), $data)->assertRedirect();
    $this->actingAs($this->admin)->post(route('fund-adjustments.store'), $data)->assertRedirect();
    $this->actingAs($this->admin)->post(route('fund-adjustments.store'), [...$data, 'idempotency_key' => null])->assertSessionHasErrors('idempotency_key');
    expect(FundAdjustment::count())->toBe(1);
    $this->actingAs($this->admin)->post(route('fund-adjustments.store'), [...$data, 'idempotency_key' => (string) Str::uuid()])->assertRedirect();
    expect(FundAdjustment::count())->toBe(2);
});

it('rejects an adjustment key reused for changed details or another officer', function (string $change): void {
    $data = ['idempotency_key' => (string) Str::uuid(), 'amount' => 5000, 'description' => 'Correction', 'recorded_at' => '2026-03-15'];
    $this->actingAs($this->admin)->post(route('fund-adjustments.store'), $data)->assertRedirect();
    $officer = $change === 'officer' ? User::factory()->financialSecretary()->create(['family_id' => $this->admin->family_id]) : $this->admin;
    $changed = match ($change) {
        'amount' => [...$data, 'amount' => 6000],
        'description' => [...$data, 'description' => 'Different'],
        'date' => [...$data, 'recorded_at' => '2026-03-16'],
        default => $data,
    };
    $this->actingAs($officer)->post(route('fund-adjustments.store'), $changed)->assertSessionHasErrors('idempotency_key');
    expect(FundAdjustment::count())->toBe(1);
})->with(['amount', 'description', 'date', 'officer']);

it('reports a reversal on an adjustment request replay', function (): void {
    $data = ['idempotency_key' => (string) Str::uuid(), 'amount' => 5000, 'description' => 'Correction', 'recorded_at' => '2026-03-15'];
    $this->actingAs($this->admin)->post(route('fund-adjustments.store'), $data)->assertRedirect();
    app(ReverseFundAdjustment::class)->handle(FundAdjustment::sole(), $this->admin, 'Synthetic reversal');
    $this->actingAs($this->admin)->post(route('fund-adjustments.store'), $data)
        ->assertRedirect()->assertSessionHas('success', 'This fund adjustment was already recorded and later reversed.');
    expect(FundAdjustment::count())->toBe(1);
});

it('does not duplicate an adjustment after a session-save conflict', function (): void {
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
        if ($injectConflict && FundAdjustment::query()->exists() && str_starts_with($sql, 'update sessions set payload=')) {
            $remote->exec("update sessions set payload='Y29uZmxpY3Q='");
            $injectConflict = false;
        }
        $statement = $remote->prepare($sql);
        $statement->execute($params);

        return Http::response(['database' => 'familyfunds-d1-web-staging', 'result' => ['results' => $statement->fetchAll(PDO::FETCH_ASSOC)]]);
    });
    config(['d1-staging.enabled' => true, 'session.driver' => 'adjustment-conflict-session', 'session.encrypt' => true, 'session.lottery' => [0, 100]]);
    app('session')->extend('adjustment-conflict-session', fn (): D1StagingSessionHandler => new D1StagingSessionHandler(
        'https://familyfunds-d1-staging-sql.familyfunds-lab.workers.dev', 'synthetic-token', 'familyfunds-d1-web-staging', 120,
    ));
    $data = ['idempotency_key' => (string) Str::uuid(), 'amount' => 5000, 'description' => 'Correction', 'recorded_at' => '2026-03-15'];
    $this->actingAs($this->admin)->post(route('fund-adjustments.store'), $data)->assertInternalServerError();
    expect($injectConflict)->toBeFalse()->and(FundAdjustment::count())->toBe(1);
    $this->actingAs($this->admin)->post(route('fund-adjustments.store'), $data)->assertRedirect();
    expect(FundAdjustment::count())->toBe(1);
});

// =========================================================================
// Reverse
// =========================================================================

it('allows super admin to reverse a fund adjustment without deleting it', function () {
    $adjustment = FundAdjustment::factory()->recordedBy($this->admin)->create();

    $this->actingAs($this->admin)
        ->post(route('fund-adjustments.reverse', $adjustment), ['reason' => 'Incorrect opening balance'])
        ->assertRedirect();

    expect(FundAdjustment::count())->toBe(1)
        ->and($adjustment->reversal()->exists())->toBeTrue();
});

it('allows financial secretary to reverse a fund adjustment', function () {
    $adjustment = FundAdjustment::factory()->recordedBy($this->financialSecretary)->create();

    $this->actingAs($this->financialSecretary)
        ->post(route('fund-adjustments.reverse', $adjustment), ['reason' => 'Incorrect opening balance'])
        ->assertRedirect();

    expect(FundAdjustment::count())->toBe(1)
        ->and($adjustment->reversal()->exists())->toBeTrue();
});

it('denies regular member from reversing a fund adjustment', function () {
    $adjustment = FundAdjustment::factory()->recordedBy($this->admin)->create();

    $this->actingAs($this->member)
        ->post(route('fund-adjustments.reverse', $adjustment), ['reason' => 'Incorrect opening balance'])
        ->assertForbidden();

    expect(FundAdjustment::count())->toBe(1);
});
