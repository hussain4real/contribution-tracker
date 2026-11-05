<?php

declare(strict_types=1);

use App\Actions\ReversePaymentBatch;
use App\Enums\GoalMeasure;
use App\Models\AuditEvent;
use App\Models\Contribution;
use App\Models\Expense;
use App\Models\Family;
use App\Models\FamilyGoal;
use App\Models\FundAdjustment;
use App\Models\Payment;
use App\Models\User;
use App\Services\FamilyGoalProgress;
use App\Services\PaymentAllocationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->family = Family::factory()->create();
    $this->admin = User::factory()->admin()->employed()->create(['family_id' => $this->family->id]);
    $this->member = User::factory()->member()->employed()->create(['family_id' => $this->family->id]);
    $this->goalData = ['name' => 'Education target', 'measure' => 'net_savings', 'target_amount' => 5000, 'starts_on' => now()->startOfMonth()->toDateString(), 'deadline' => now()->endOfMonth()->toDateString(), 'idempotency_key' => (string) Str::uuid()];
});

it('creates visual goals idempotently without creating financial entries', function () {
    $this->actingAs($this->admin)->post(route('family.goals.store'), $this->goalData)->assertRedirect();
    $this->post(route('family.goals.store'), $this->goalData)->assertRedirect();
    expect(FamilyGoal::count())->toBe(1)->and(Payment::count())->toBe(0)->and(Expense::count())->toBe(0)
        ->and(FundAdjustment::count())->toBe(0)->and(AuditEvent::where('action', 'family.goal_created')->count())->toBe(1);
    expect(FamilyGoal::sole()->family->id)->toBe($this->family->id)->and($this->family->goals()->count())->toBe(1);
    $this->postJson(route('family.goals.store'), [...$this->goalData, 'target_amount' => 6000])->assertUnprocessable();
});

it('reconciles savings and collection targets with effective entry dates and reversals', function () {
    Contribution::factory()->forUser($this->member)->currentMonth()->create(['expected_amount' => 4000]);
    $batch = app(PaymentAllocationService::class)->createBatch(member: $this->member, amount: 3000, paidAt: now(), recordedBy: $this->admin, family: $this->family);
    Expense::factory()->recordedBy($this->admin)->create(['amount' => 400, 'spent_at' => now()]);
    Expense::factory()->recordedBy($this->admin)->create(['amount' => 900, 'spent_at' => now()->subMonth()->startOfMonth()]);
    Expense::factory()->recordedBy($this->admin)->create(['amount' => 700, 'spent_at' => now()->addMonth()->startOfMonth()]);
    FundAdjustment::factory()->recordedBy($this->admin)->create(['amount' => 100, 'recorded_at' => now()]);
    $savings = FamilyGoal::factory()->create(['family_id' => $this->family->id, 'target_amount' => 5000]);
    FamilyGoal::factory()->create(['family_id' => $this->family->id, 'measure' => GoalMeasure::Contributions, 'target_amount' => 2000]);
    $progress = app(FamilyGoalProgress::class);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $goals = $progress->query($this->family)->orderBy('id')->get();
    expect(DB::getQueryLog())->toHaveCount(1);
    DB::disableQueryLog();
    $first = $progress->present($goals->firstOrFail(), $this->family, true);
    $second = $progress->present($goals->skip(1)->firstOrFail(), $this->family, true);
    expect($first['progress'])->toBe(2700)->and($first['remaining'])->toBe(2300)->and($first['percentage'])->toBe(54.0)
        ->and($second['progress'])->toBe(3000)->and($second['percentage'])->toBe(100.0)->and($second['status'])->toBe('reached');
    $this->actingAs($this->admin)->get($first['source_url'] ?? throw new RuntimeException('Missing goal source'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('overview', null)->where('rangeSummary.received', 3000)->where('rangeSummary.expenses', 400)->where('rangeSummary.adjustments', 100));
    app(ReversePaymentBatch::class)->handle($batch, $this->admin, 'Synthetic reversal');
    $after = $progress->present($progress->query($this->family)->findOrFail($savings->id), $this->family, false);
    expect($after['progress'])->toBe(-300)->and($after['percentage'])->toBe(0.0)->and($after['remaining'])->toBe(5300)->and($after['source_url'])->toBeNull();
});

it('supports future overdue and archived targets with audited edits and visibility changes', function () {
    $goal = FamilyGoal::factory()->create(['family_id' => $this->family->id, 'starts_on' => now()->addMonth()->startOfMonth(), 'deadline' => now()->addMonth()->endOfMonth()]);
    $this->actingAs($this->admin)->get(route('family.goals.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('goals.data.0.status', 'upcoming')->where('goals.data.0.source_url', null));
    $this->put(route('family.goals.update', $goal), [...$this->goalData, 'starts_on' => now()->subMonth()->startOfMonth()->toDateString(), 'deadline' => now()->subMonth()->endOfMonth()->toDateString()])->assertRedirect();
    $this->get(route('family.goals.index'))->assertInertia(fn (Assert $page) => $page->where('goals.data.0.status', 'overdue'));
    $this->patch(route('family.goals.archive', $goal), ['archived' => true])->assertRedirect();
    $this->patch(route('family.goals.archive', $goal), ['archived' => true])->assertRedirect();
    $this->get(route('family.goals.index'))->assertInertia(fn (Assert $page) => $page->has('goals.data', 0));
    $this->get(route('family.goals.index', ['archived' => 1]))->assertInertia(fn (Assert $page) => $page->where('goals.data.0.status', 'archived'));
    $this->patch(route('family.goals.archive', $goal), ['archived' => false])->assertRedirect();
    expect(AuditEvent::where('action', 'family.goal_updated')->count())->toBe(1)
        ->and(AuditEvent::where('action', 'family.goal_archived')->count())->toBe(1)
        ->and(AuditEvent::where('action', 'family.goal_restored')->count())->toBe(1);
});

it('enforces goal management and financial visibility within the selected family', function () {
    $goal = FamilyGoal::factory()->create(['family_id' => $this->family->id]);
    $this->actingAs($this->member)->get(route('family.goals.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('can_manage', false)->where('goals.data.0.source_url', null));
    $this->postJson(route('family.goals.store'), $this->goalData)->assertForbidden();
    $this->putJson(route('family.goals.update', $goal), $this->goalData)->assertForbidden();
    $this->patchJson(route('family.goals.archive', $goal), ['archived' => true])->assertForbidden();
    $this->family->update(['member_financial_visibility' => 'personal']);
    $this->get(route('family.goals.index'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('family.goals.index'))->assertOk();
    $foreign = FamilyGoal::factory()->create();
    $this->putJson(route('family.goals.update', $foreign), $this->goalData)->assertForbidden();
    $this->patchJson(route('family.goals.archive', $foreign), ['archived' => true])->assertForbidden();
});

it('validates dates measures amounts and complete source ranges', function () {
    $this->actingAs($this->admin);
    foreach ([['target_amount' => 0], ['deadline' => now()->subYear()->toDateString()], ['measure' => 'separate_fund'], ['name' => ''], ['idempotency_key' => 'invalid']] as $change) {
        $this->postJson(route('family.goals.store'), [...$this->goalData, ...$change])->assertUnprocessable();
    }
    $this->getJson(route('family.activity', ['start_date' => '2026-01-01']))->assertUnprocessable();
    $this->getJson(route('family.activity', ['end_date' => '2026-01-01']))->assertUnprocessable();
    $this->getJson(route('family.activity', ['start_date' => '2026-02-01', 'end_date' => '2026-01-01']))->assertUnprocessable();
    $this->getJson(route('family.activity', ['period' => '2026-01', 'start_date' => '2026-01-01', 'end_date' => '2026-01-02']))->assertUnprocessable();
});
