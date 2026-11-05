<?php

declare(strict_types=1);

use App\Actions\CorrectExpense;
use App\Actions\ReverseExpense;
use App\Ai\Tools\RecordExpense;
use App\Jobs\PurgeArchivedFamily;
use App\Models\AuditEvent;
use App\Models\Expense;
use App\Models\ExpenseApproval;
use App\Models\ExpenseRequest;
use App\Models\Family;
use App\Models\PlatformPlan;
use App\Models\User;
use App\Support\EffectiveLedger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    $plan = PlatformPlan::query()->firstOrCreate(['slug' => 'organization'], ['name' => 'Organization', 'price' => 10000, 'features' => [], 'is_active' => true]);
    $this->family = Family::factory()->create(['platform_plan_id' => $plan->id, 'subscription_status' => 'active']);
    $this->owner = User::factory()->admin()->create(['family_id' => $this->family->id]);
    $this->requester = User::factory()->financialSecretary()->create(['family_id' => $this->family->id]);
    $this->approver = User::factory()->financialSecretary()->create(['family_id' => $this->family->id]);
    $this->member = User::factory()->member()->create(['family_id' => $this->family->id]);
    $this->family->update(['created_by' => $this->owner->id, 'expense_approval_settings' => ['enabled' => true, 'threshold' => 1000, 'approvers' => 2]]);
    $this->data = ['amount' => 2000, 'description' => 'School repairs', 'spent_at' => now()->toDateString(), 'idempotency_key' => (string) Str::uuid()];
});

it('requires distinct independent approvals and posts a paid request once', function () {
    $this->actingAs($this->requester)->postJson(route('expense-requests.store'), $this->data)->assertRedirect();
    $this->postJson(route('expense-requests.store'), $this->data)->assertRedirect();
    $request = ExpenseRequest::sole();
    expect(Expense::count())->toBe(0)->and(app(EffectiveLedger::class)->balance($this->family->id))->toBe(0);
    $this->postJson(route('expense-requests.decide', $request), ['decision' => 'approved'])->assertForbidden();
    $this->postJson(route('expense-requests.pay', $request))->assertUnprocessable();
    $this->actingAs($this->approver)->postJson(route('expense-requests.decide', $request), ['decision' => 'approved'])->assertRedirect();
    $this->postJson(route('expense-requests.decide', $request), ['decision' => 'approved'])->assertRedirect();
    expect(ExpenseApproval::count())->toBe(1);
    $this->postJson(route('expense-requests.pay', $request))->assertUnprocessable();
    $this->actingAs($this->owner)->postJson(route('expense-requests.decide', $request), ['decision' => 'approved'])->assertRedirect();
    expect(Expense::count())->toBe(0);
    $this->postJson(route('expense-requests.pay', $request))->assertRedirect();
    $this->postJson(route('expense-requests.pay', $request))->assertRedirect();
    expect(Expense::count())->toBe(1)->and($request->refresh()->status)->toBe('paid')
        ->and(app(EffectiveLedger::class)->balance($this->family->id))->toBe(-2000)
        ->and(AuditEvent::query()->where('action', 'expense_request.paid')->count())->toBe(1);
    $this->get(route('expense-requests.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('requests.data.0.can_pay', false)->where('requests.data.0.approved', 2));
});

it('rejects a request without posting money and preserves the decision', function () {
    $request = ExpenseRequest::factory()->create(['family_id' => $this->family->id, 'requested_by' => $this->requester->id]);
    $this->actingAs($this->owner)->postJson(route('expense-requests.decide', $request), ['decision' => 'rejected'])->assertUnprocessable();
    $payload = ['decision' => 'rejected', 'reason' => 'Need an invoice'];
    $this->postJson(route('expense-requests.decide', $request), $payload)->assertRedirect();
    $this->postJson(route('expense-requests.decide', $request), $payload)->assertRedirect();
    $this->postJson(route('expense-requests.decide', $request), ['decision' => 'approved'])->assertUnprocessable();
    $this->postJson(route('expense-requests.pay', $request))->assertUnprocessable();
    expect($request->refresh()->status)->toBe('rejected')->and(Expense::count())->toBe(0)->and(ExpenseApproval::count())->toBe(1);
});

it('enforces the threshold on direct web and model posting including corrections', function () {
    $this->actingAs($this->requester)->postJson(route('expenses.store'), $this->data)->assertUnprocessable();
    $below = [...$this->data, 'amount' => 999, 'idempotency_key' => (string) Str::uuid()];
    $this->postJson(route('expenses.store'), $below)->assertRedirect();
    $expense = Expense::sole();
    expect(fn () => app(CorrectExpense::class)->handle($expense, $this->owner, 'Correction', 2000, 'Changed', now()))
        ->toThrow(ValidationException::class);
    expect(Expense::count())->toBe(1)->and($expense->isReversed())->toBeFalse();
    expect(fn () => Expense::factory()->recordedBy($this->requester)->create(['amount' => 1000]))
        ->toThrow(ValidationException::class);
});

it('rechecks stronger current policies without weakening captured request requirements', function () {
    $request = ExpenseRequest::factory()->create(['family_id' => $this->family->id, 'requested_by' => $this->requester->id,
        'required_approvals' => 1, 'amount' => 2000]);
    $this->family->update(['expense_approval_settings' => ['enabled' => true, 'threshold' => 1000, 'approvers' => 1]]);
    $this->actingAs($this->approver)->postJson(route('expense-requests.decide', $request), ['decision' => 'approved'])->assertRedirect();
    $this->family->update(['expense_approval_settings' => ['enabled' => true, 'threshold' => 1000, 'approvers' => 2]]);
    $this->postJson(route('expense-requests.pay', $request))->assertUnprocessable();
    $this->get(route('expense-requests.index'))->assertInertia(fn (Assert $page) => $page->where('requests.data.0.can_pay', false));
    $this->actingAs($this->owner)->postJson(route('expense-requests.decide', $request), ['decision' => 'approved'])->assertRedirect();
    $this->family->update(['expense_approval_settings' => ['enabled' => false, 'threshold' => 1000, 'approvers' => 1]]);
    $this->postJson(route('expense-requests.pay', $request))->assertRedirect();
    app(ReverseExpense::class)->handle(Expense::sole(), $this->owner, 'Cancelled');
    $this->postJson(route('expense-requests.pay', $request))->assertRedirect();
    expect(Expense::count())->toBe(1)->and(app(EffectiveLedger::class)->balance($this->family->id))->toBe(0);
});

it('requires the owner and Organization plan to enable rules and audits settings changes', function () {
    $settings = ['member_financial_visibility' => 'transactions', 'enabled' => true, 'threshold' => 5000, 'approvers' => 1];
    $this->actingAs($this->requester)->putJson(route('financial-governance.update'), $settings)->assertForbidden();
    $otherAdmin = User::factory()->admin()->create(['family_id' => $this->family->id]);
    $this->actingAs($otherAdmin)->putJson(route('financial-governance.update'), $settings)->assertForbidden();
    $this->actingAs($this->owner)->putJson(route('financial-governance.update'), $settings)->assertRedirect();
    expect($this->family->refresh()->member_financial_visibility)->toBe('transactions')
        ->and(AuditEvent::query()->where('action', 'family.financial_settings_updated')->count())->toBe(1);
    $this->family->update(['subscription_status' => 'cancelled']);
    $this->putJson(route('financial-governance.update'), [...$settings, 'threshold' => 6000])->assertUnprocessable();
    $this->postJson(route('expense-requests.store'), $this->data)->assertForbidden();
    $this->postJson(route('expenses.store'), [...$this->data, 'amount' => 5000])->assertUnprocessable();
});

it('isolates requests and decisions by family and role', function () {
    $request = ExpenseRequest::factory()->create(['family_id' => $this->family->id, 'requested_by' => $this->requester->id]);
    $this->actingAs($this->member)->get(route('expense-requests.index'))->assertForbidden();
    $this->postJson(route('expense-requests.decide', $request), ['decision' => 'approved'])->assertForbidden();
    $this->postJson(route('expense-requests.pay', $request))->assertForbidden();
    $outsider = User::factory()->admin()->create();
    $this->actingAs($outsider)->postJson(route('expense-requests.decide', $request), ['decision' => 'approved'])->assertForbidden();
    $this->postJson(route('expense-requests.pay', $request))->assertForbidden();
    $this->get(route('expense-requests.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('requests.data', 0));
});

it('rejects changed replay inputs and malformed approval settings', function () {
    $this->actingAs($this->requester)->postJson(route('expense-requests.store'), $this->data)->assertRedirect();
    $this->postJson(route('expense-requests.store'), [...$this->data, 'amount' => 4000])->assertUnprocessable();
    $this->family->update(['expense_approval_settings' => ['enabled' => true, 'threshold' => 'invalid', 'approvers' => 1]]);
    $this->postJson(route('expenses.store'), $this->data)->assertUnprocessable();
});

it('leaves no expense after a failed commit and posts exactly once on retry', function () {
    $request = ExpenseRequest::factory()->create(['family_id' => $this->family->id, 'requested_by' => $this->requester->id,
        'required_approvals' => 0, 'amount' => 500]);
    $fail = true;
    Expense::created(function () use (&$fail): void {
        if ($fail) {
            $fail = false;
            throw new RuntimeException('Synthetic posting interruption');
        }
    });
    $this->actingAs($this->owner)->postJson(route('expense-requests.pay', $request))->assertStatus(500);
    expect(Expense::count())->toBe(0)->and($request->refresh()->status)->toBe('submitted');
    $this->postJson(route('expense-requests.pay', $request))->assertRedirect();
    $this->postJson(route('expense-requests.pay', $request))->assertRedirect();
    expect(Expense::count())->toBe(1)->and(AuditEvent::query()->where('action', 'expense.posted')->count())->toBe(1);
});

it('blocks the AI tool from bypassing approval requirements', function () {
    $tool = new RecordExpense($this->requester);
    $result = json_decode($tool->handle(new Request([...$this->data, 'confirmed' => true])), true);
    expect($result)->toHaveKey('status', 'approval_required')->and(Expense::count())->toBe(0);
});

it('cannot enable an unattainable approver count or use an expired subscription', function () {
    $this->actingAs($this->owner)->putJson(route('financial-governance.update'), [
        'member_financial_visibility' => 'summary', 'enabled' => true, 'threshold' => 1000, 'approvers' => 5,
    ])->assertUnprocessable()->assertJsonValidationErrors('approvers');
    $this->family->update(['current_period_end' => now()->subDay()]);
    $this->postJson(route('expense-requests.store'), $this->data)->assertForbidden();
});

it('enforces unique decisions at the database boundary', function () {
    $request = ExpenseRequest::factory()->create(['family_id' => $this->family->id, 'requested_by' => $this->requester->id]);
    $values = ['family_id' => $this->family->id, 'expense_request_id' => $request->id, 'user_id' => $this->approver->id, 'decision' => 'approved'];
    ExpenseApproval::query()->create($values);
    expect(fn () => ExpenseApproval::query()->create($values))->toThrow(QueryException::class);
    expect(ExpenseApproval::count())->toBe(1);
});

it('keeps request keys separate from direct posting and refuses a new decision on a rejected request', function () {
    $this->family->update(['expense_approval_settings' => null]);
    $this->actingAs($this->owner)->postJson(route('expenses.store'), $this->data)->assertRedirect();
    $this->postJson(route('expense-requests.store'), $this->data)->assertUnprocessable();
    $new = [...$this->data, 'idempotency_key' => (string) Str::uuid()];
    $this->actingAs($this->requester)->postJson(route('expense-requests.store'), $new)->assertRedirect();
    $this->postJson(route('expenses.store'), $new)->assertUnprocessable();
    $request = ExpenseRequest::sole();
    $this->actingAs($this->owner)->postJson(route('expense-requests.decide', $request), ['decision' => 'rejected', 'reason' => 'Not needed'])->assertRedirect();
    $this->actingAs($this->approver)->postJson(route('expense-requests.decide', $request), ['decision' => 'approved'])->assertUnprocessable();
    expect(Expense::count())->toBe(1);
});

it('rechecks a legal hold at purge time before removing private documents', function () {
    Storage::fake('local');
    $this->family->update(['archived_at' => now()->subDays(40), 'purge_after' => now()->subDay()]);
    Storage::disk('local')->put('expense-documents/'.$this->family->id.'/kept.pdf', 'synthetic');
    $seen = 0;
    $id = $this->family->id;
    Family::retrieved(function (Family $family) use (&$seen, $id): void {
        if ($family->id === $id && ++$seen === 2) {
            $family->legal_hold_at = now();
        }
    });
    (new PurgeArchivedFamily($id))->handle();
    expect(Family::query()->find($id))->not->toBeNull();
    Storage::disk('local')->assertExists('expense-documents/'.$id.'/kept.pdf');
});

it('preserves approval evidence when an approver account is deleted', function () {
    $request = ExpenseRequest::factory()->create(['family_id' => $this->family->id, 'requested_by' => $this->requester->id, 'amount' => 2000]);
    $this->actingAs($this->approver)->postJson(route('expense-requests.decide', $request), ['decision' => 'approved'])->assertRedirect();
    DB::table('users')->where('id', $this->approver->id)->delete();
    $this->actingAs($this->owner)->postJson(route('expense-requests.decide', $request), ['decision' => 'approved'])->assertRedirect();
    $this->get(route('expense-requests.index'))->assertInertia(fn (Assert $page) => $page
        ->where('requests.data.0.approved', 2)->where('requests.data.0.decisions.0.by', 'Former member'));
    $this->postJson(route('expense-requests.pay', $request))->assertRedirect();
    expect(Expense::count())->toBe(1)->and(ExpenseApproval::count())->toBe(2);
});

it('keeps recorded approval decisions immutable', function () {
    $request = ExpenseRequest::factory()->create(['family_id' => $this->family->id, 'requested_by' => $this->requester->id]);
    $approval = ExpenseApproval::query()->create([
        'family_id' => $this->family->id, 'expense_request_id' => $request->id,
        'user_id' => $this->owner->id, 'decision' => 'approved',
    ]);
    expect(fn () => $approval->update(['reason' => 'Changed later']))->toThrow(LogicException::class);
    expect(fn () => $approval->delete())->toThrow(LogicException::class);
    expect($approval->refresh()->reason)->toBeNull()->and(ExpenseApproval::count())->toBe(1);
});

it('reverses the additive governance schema without removing existing expense rows', function () {
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $connection->getPdo()->exec('pragma foreign_keys=on');
    $schema = $connection->getSchemaBuilder();
    foreach (['users', 'families', 'expenses'] as $name) {
        $schema->create($name, function (Blueprint $table): void {
            $table->id();
        });
    }
    $connection->table('expenses')->insert(['id' => 1]);
    $original = Schema::getFacadeRoot();
    Schema::swap($schema);
    try {
        $migrations = [];
        foreach ([
            '2026_11_05_081631_add_financial_governance_to_families.php',
            '2026_11_05_081632_create_expense_requests_table.php',
            '2026_11_05_081633_create_expense_approvals_table.php',
            '2026_11_05_081634_add_expense_request_id_to_expenses.php',
            '2026_11_05_081635_create_expense_documents_table.php',
        ] as $file) {
            $migration = require database_path('migrations/'.$file);
            if (! $migration instanceof Migration) {
                throw new RuntimeException('Expected the governance migration.');
            }
            (new ReflectionMethod($migration, 'up'))->invoke($migration);
            $migrations[] = $migration;
        }
        expect($schema->hasTable('expense_requests'))->toBeTrue()->and($schema->hasColumn('expenses', 'expense_request_id'))->toBeTrue();
        foreach (array_reverse($migrations) as $migration) {
            (new ReflectionMethod($migration, 'down'))->invoke($migration);
        }
        expect($schema->hasTable('expense_requests'))->toBeFalse()
            ->and($schema->hasColumn('expenses', 'expense_request_id'))->toBeFalse()
            ->and($schema->hasColumn('families', 'member_financial_visibility'))->toBeFalse()
            ->and($connection->table('expenses')->count())->toBe(1);
    } finally {
        Schema::swap($original);
    }
});
