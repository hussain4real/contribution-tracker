<?php

declare(strict_types=1);

use App\Ai\Tools\GenerateContributions;
use App\Ai\Tools\RecordExpense;
use App\Ai\Tools\RecordFundAdjustment;
use App\Ai\Tools\RecordPayment;
use App\Enums\AiActionKind;
use App\Enums\Role;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\User;
use App\Services\AiActionDrafts;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    $this->family = Family::factory()->create();
    $this->admin = User::factory()->admin()->employed()->create(['family_id' => $this->family->id]);
    $this->member = User::factory()->member()->employed()->create(['family_id' => $this->family->id]);
    $this->drafts = app(AiActionDrafts::class);
});

it('opens every financial draft on an existing manual review form without writing financial records', function () {
    foreach ([
        [AiActionKind::Payment, ['member_id' => $this->member->id, 'member' => $this->member->name, 'amount' => 1000, 'paid_at' => today()->toDateString()], 'Payments/Create'],
        [AiActionKind::Expense, ['amount' => 500, 'description' => 'Draft expense', 'spent_at' => today()->toDateString()], 'Expenses/Create'],
        [AiActionKind::Adjustment, ['amount' => -50, 'description' => 'Draft adjustment', 'recorded_at' => today()->toDateString()], 'FundAdjustments/Index'],
        [AiActionKind::Contributions, ['year' => 2026, 'month' => 11], 'Contributions/Index'],
    ] as [$kind, $values, $component]) {
        $draft = $this->drafts->create($this->admin, $this->family, $kind, $values);
        $this->actingAs($this->admin)->get($draft['review_url'])->assertOk()->assertInertia(fn (Assert $page) => $page->component($component)->where('aiDraft', $draft['details']));
    }
    foreach (['payments', 'payment_batches', 'expenses', 'fund_adjustments', 'contributions'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
});

it('binds encrypted expiring drafts to their actor family action and payment member', function () {
    $draft = $this->drafts->create($this->admin, $this->family, AiActionKind::Payment, ['member_id' => $this->member->id, 'member' => $this->member->name, 'amount' => 1000, 'paid_at' => today()->toDateString()]);
    parse_str(parse_url($draft['review_url'], PHP_URL_QUERY) ?: '', $query);
    if (! is_string($query['ai_draft'] ?? null)) {
        throw new RuntimeException('Missing draft key');
    }
    expect(Cache::get('ai-action-draft:'.$query['ai_draft']))->not->toContain($this->member->name);
    $other = User::factory()->admin()->create(['family_id' => $this->family->id]);
    $this->actingAs($other)->get($draft['review_url'])->assertForbidden();
    $this->actingAs($this->admin)->get(route('payments.create', ['member' => $other->id, ...$query]))->assertForbidden();
    $this->get(route('expenses.create', $query))->assertForbidden();
    $foreign = Family::factory()->create();
    $this->admin->families()->attach($foreign->id, ['role' => Role::Admin->value]);
    $this->get(route('payments.create', ['current_family' => $foreign->slug, 'member' => $this->member->id, ...$query]))->assertNotFound();
    $this->travel(11)->minutes();
    $this->get($draft['review_url'])->assertStatus(410);
    $this->get(route('expenses.create', ['ai_draft' => 'bad-key']))->assertNotFound();
});

it('requires current privileges even when the original user instance has cached roles', function () {
    $draft = $this->drafts->create($this->admin, $this->family, AiActionKind::Expense, ['amount' => 100, 'description' => 'Expired authority', 'spent_at' => today()->toDateString()]);
    $this->admin->load('familyMemberships');
    FamilyMembership::where('family_id', $this->family->id)->where('user_id', $this->admin->id)->update(['role' => Role::Member->value]);
    expect($this->drafts->allowed($this->admin, $this->family, AiActionKind::Expense))->toBeFalse();
    $this->actingAs($this->admin)->get($draft['review_url'])->assertForbidden();
});

it('passes a stable request key to the human form and posts only on explicit submission', function () {
    $draft = $this->drafts->create($this->admin, $this->family, AiActionKind::Expense, ['amount' => 500, 'description' => 'Approved by human', 'spent_at' => today()->toDateString()]);
    parse_str(parse_url($draft['review_url'], PHP_URL_QUERY) ?: '', $query);
    $this->actingAs($this->admin)->get($draft['review_url'])->assertInertia(fn (Assert $page) => $page->where('idempotencyKey', $query['ai_draft']));
    $this->assertDatabaseCount('expenses', 0);
    $values = [...$draft['details'], 'idempotency_key' => $query['ai_draft']];
    $this->post(route('expenses.store'), $values)->assertRedirect();
    $this->post(route('expenses.store'), $values)->assertRedirect();
    $this->assertDatabaseCount('expenses', 1);
    $this->get($draft['review_url'])->assertInertia(fn (Assert $page) => $page->where('idempotencyKey', $query['ai_draft']));
});

it('rejects a corrupt cached draft rather than prefilling untrusted details', function () {
    $draft = $this->drafts->create($this->admin, $this->family, AiActionKind::Expense, ['amount' => 10, 'description' => 'Sealed draft', 'spent_at' => today()->toDateString()]);
    parse_str(parse_url($draft['review_url'], PHP_URL_QUERY) ?: '', $query);
    if (! is_string($query['ai_draft'] ?? null)) {
        throw new RuntimeException('Missing draft key');
    }
    Cache::put('ai-action-draft:'.$query['ai_draft'], 'tampered sealed draft', 60);
    $this->actingAs($this->admin)->get($draft['review_url'])->assertNotFound();
    $this->assertDatabaseCount('expenses', 0);
});

it('blocks each financial tool after privilege revocation despite cached chat roles', function () {
    $this->admin->load('familyMemberships');
    FamilyMembership::where('family_id', $this->family->id)->where('user_id', $this->admin->id)->update(['role' => Role::Member->value]);
    foreach ([
        new RecordPayment($this->admin),
        new RecordExpense($this->admin),
        new RecordFundAdjustment($this->admin),
        new GenerateContributions($this->admin),
    ] as $tool) {
        $result = decodeToolResult($tool->handle(new Request(['amount' => 100, 'description' => 'Revoked authority'])));
        expect($result['error'])->toBe('You do not have permission to prepare this action.');
    }
    foreach (['payments', 'expenses', 'fund_adjustments', 'contributions'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
});
