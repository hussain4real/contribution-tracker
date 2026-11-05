<?php

declare(strict_types=1);

use App\Actions\ReverseExpense;
use App\Models\AuditEvent;
use App\Models\Contribution;
use App\Models\Expense;
use App\Models\Family;
use App\Models\FundAdjustment;
use App\Models\Payment;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 15));
    $this->family = Family::factory()->create();
    $this->admin = User::factory()->admin()->create(['family_id' => $this->family->id]);
    $this->member = User::factory()->member()->employed()->create(['family_id' => $this->family->id]);
});

it('reconciles the dashboard month with filtered effective ledger transactions', function () {
    $contribution = Contribution::factory()->forUser($this->member)->create(['year' => 2026, 'month' => 11, 'expected_amount' => 4000]);
    Payment::factory()->create(['contribution_id' => $contribution->id, 'amount' => 1000, 'paid_at' => '2026-10-31']);
    Payment::factory()->create(['contribution_id' => $contribution->id, 'amount' => 500, 'paid_at' => '2026-11-01']);
    Payment::factory()->create(['contribution_id' => $contribution->id, 'amount' => 100, 'paid_at' => '2026-12-01']);
    FundAdjustment::factory()->recordedBy($this->admin)->create(['amount' => 200, 'recorded_at' => '2026-11-02']);
    Expense::factory()->recordedBy($this->admin)->create(['amount' => 300, 'spent_at' => '2026-11-03']);
    $reversed = Expense::factory()->recordedBy($this->admin)->create(['amount' => 999, 'spent_at' => '2026-11-04']);
    app(ReverseExpense::class)->handle($reversed, $this->admin, 'Duplicate entry');
    $other = User::factory()->admin()->create();
    Expense::factory()->recordedBy($other)->create(['amount' => 9000, 'spent_at' => '2026-11-01']);

    $expected = ['period' => '2026-11', 'received' => 500, 'expenses' => 300, 'adjustments' => 200,
        'opening_balance' => 1000, 'closing_balance' => 1400, 'outstanding' => 2400];
    $this->actingAs($this->admin)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('financial_overview', $expected));
    $this->get(route('family.activity', ['period' => '2026-11']))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('overview', $expected)->has('entries.data', 3));
    $this->get(route('family.activity', ['period' => '2026-11', 'search' => 'financial.reversed']))
        ->assertInertia(fn (Assert $page) => $page->has('events.data', 1)
            ->where('events.data.0.reason', 'Duplicate entry'));
    $this->get(route('family.activity', ['period' => '2026-11', 'type' => 'expense']))->assertInertia(fn (Assert $page) => $page
        ->has('entries.data', 1)->where('entries.data.0.amount', 300));
});

it('keeps officer financial activity unavailable to members and other families', function () {
    $this->actingAs($this->member)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->missing('financial_overview'));
    $this->get(route('family.activity'))->assertForbidden();
    $other = User::factory()->admin()->create();
    $this->actingAs($other)->get(route('family.activity', ['current_family' => $this->family->slug]))->assertForbidden();
});

it('searches paginated family events without exposing private audit snapshots', function () {
    AuditEvent::factory()->create(['family_id' => $this->family->id, 'actor_id' => $this->admin->id,
        'action' => 'expense.posted', 'before' => ['secret' => 'private'], 'metadata' => ['credential' => 'hidden']]);
    AuditEvent::factory()->create(['family_id' => Family::factory()->create()->id, 'action' => 'expense.posted']);
    $this->actingAs($this->admin)->get(route('family.activity', ['search' => 'expense.posted', 'period' => '2026-11']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->has('events.data', 1)
        ->where('events.data.0.actor', $this->admin->name)->missing('events.data.0.before')->missing('events.data.0.metadata'));
});

it('rejects malformed month and filter inputs', function () {
    $this->actingAs($this->admin)->getJson(route('family.activity', ['period' => '2026-99', 'type' => 'secret']))
        ->assertUnprocessable()->assertJsonValidationErrors(['period', 'type']);
});

it('keeps historical members in the ledger and prevents overpayments offsetting other outstanding amounts', function () {
    $paid = Contribution::factory()->forUser($this->member)->create(['year' => 2026, 'month' => 11, 'expected_amount' => 1000]);
    Payment::factory()->create(['contribution_id' => $paid->id, 'paid_at' => '2026-11-02', 'amount' => 2000]);
    $unpaid = User::factory()->member()->create(['family_id' => $this->family->id]);
    Contribution::factory()->forUser($unpaid)->create(['year' => 2026, 'month' => 11, 'expected_amount' => 3000]);
    $this->member->membershipForFamilyId($this->family->id)->update(['archived_at' => now()]);
    $this->actingAs($this->admin)->get(route('family.activity', ['type' => 'payment']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('overview.received', 2000)->where('overview.outstanding', 3000)
        ->has('entries.data', 1)->where('entries.data.0.kind', 'payment'));
});

it('keeps paginated months stable and reports adjustments on their effective date', function () {
    for ($i = 1; $i <= 21; $i++) {
        FundAdjustment::factory()->recordedBy($this->admin)->create(['amount' => -50, 'recorded_at' => '2026-11-01', 'description' => 'November correction']);
    }
    AuditEvent::factory()->create(['family_id' => $this->family->id, 'actor_id' => null,
        'action' => 'family.updated', 'after' => ['reason' => 'Private settings note']]);
    $this->actingAs($this->admin)->get(route('family.activity', ['period' => '2026-11', 'type' => 'adjustment', 'search' => 'November']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('overview.adjustments', -1050)->has('entries.data', 20)->where('entries.total', 21));
    $this->get(route('family.activity', ['period' => '2026-11', 'type' => 'adjustment', 'search' => 'November', 'page' => 2]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->has('entries.data', 1));
    $this->get(route('family.activity', ['search' => 'family.updated']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('events.data.0.actor', 'System')->where('events.data.0.reason', null));
    $this->get(route('family.activity', ['period' => '2026-12']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('overview.opening_balance', -1050)->has('entries.data', 0));
});
