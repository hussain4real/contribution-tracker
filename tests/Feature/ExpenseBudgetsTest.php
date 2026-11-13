<?php

declare(strict_types=1);

use App\Actions\CorrectExpense;
use App\Actions\ReverseExpense;
use App\Jobs\PurgeArchivedFamily;
use App\Models\AuditEvent;
use App\Models\Expense;
use App\Models\ExpenseApproval;
use App\Models\ExpenseBudget;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRequest;
use App\Models\Family;
use App\Models\PlatformPlan;
use App\Models\User;
use App\Services\ExpenseBudgetSpending;
use App\Support\EffectiveLedger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\AssertableJsonString;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 13));
    $this->family = Family::factory()->create();
    $this->owner = User::factory()->admin()->create(['family_id' => $this->family->id]);
    $this->officer = User::factory()->financialSecretary()->create(['family_id' => $this->family->id]);
    $this->category = ExpenseCategory::factory()->create(['family_id' => $this->family->id, 'name' => 'Welfare']);
    $this->budget = ExpenseBudget::factory()->create(['family_id' => $this->family->id, 'expense_category_id' => $this->category->id, 'month' => '2026-11-01', 'amount' => 1000]);
});

it('adds and reverses budgets without changing existing expense data', function () {
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $connection->getPdo()->exec('pragma foreign_keys=on');
    $schema = $connection->getSchemaBuilder();
    $schema->create('families', fn (Blueprint $table) => $table->id());
    $connection->table('families')->insert(['id' => 1]);
    foreach (['expenses', 'expense_requests'] as $name) {
        $schema->create($name, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('family_id')->constrained();
            $table->date('spent_at');
            $table->unsignedBigInteger('amount');
        });
        $connection->table($name)->insert(['id' => 1, 'family_id' => 1, 'spent_at' => '2026-11-13', 'amount' => 125]);
    }
    $schema->create('budget_test_events', fn (Blueprint $table) => $table->integer('expense_id'));
    $schema->create('budget_test_links', function (Blueprint $table): void {
        $table->foreignId('expense_id')->constrained('expenses')->cascadeOnDelete();
    });
    $connection->table('budget_test_links')->insert(['expense_id' => 1]);
    $connection->statement('CREATE TRIGGER expense_budget_test_guard AFTER UPDATE ON expenses BEGIN INSERT INTO budget_test_events VALUES(new.id); END');
    $original = Schema::getFacadeRoot();
    Schema::swap($schema);
    try {
        $migration = require database_path('migrations/2026_11_13_034236_create_expense_budgets_and_categories.php');
        if (! $migration instanceof Migration) {
            throw new RuntimeException('Expected the expense budget migration.');
        }
        (new ReflectionMethod($migration, 'up'))->invoke($migration);
        expect($connection->table('expenses')->value('expense_category_id'))->toBeNull();
        $connection->table('expense_categories')->insert(['id' => 1, 'family_id' => 1, 'name' => 'Welfare']);
        $connection->table('expense_budgets')->insert(['family_id' => 1, 'expense_category_id' => 1, 'month' => '2026-11-01', 'amount' => 1000]);
        $connection->table('expenses')->where('id', 1)->update(['expense_category_id' => 1]);
        expect($connection->table('budget_test_events')->count())->toBe(1)
            ->and($connection->table('budget_test_links')->count())->toBe(1);
        (new ReflectionMethod($migration, 'down'))->invoke($migration);
        expect($schema->hasTable('expense_budgets'))->toBeFalse()
            ->and($schema->hasTable('expense_categories'))->toBeFalse();
        foreach (['expenses', 'expense_requests'] as $name) {
            expect($schema->hasColumn($name, 'expense_category_id'))->toBeFalse()
                ->and($connection->table($name)->count())->toBe(1)
                ->and($connection->table($name)->value('amount'))->toBe(125);
        }
        (new ReflectionMethod($migration, 'up'))->invoke($migration);
        $connection->table('expenses')->where('id', 1)->update(['amount' => 125]);
        expect($schema->hasColumn('expenses', 'expense_category_id'))->toBeTrue()
            ->and($connection->table('expenses')->value('amount'))->toBe(125)
            ->and($connection->table('budget_test_events')->count())->toBe(2)
            ->and($connection->table('budget_test_links')->count())->toBe(1)
            ->and($connection->select('PRAGMA foreign_key_check'))->toBe([]);
    } finally {
        Schema::swap($original);
    }
});

it('saves one monthly category limit with audit history and no ledger movement', function () {
    $payload = ['expense_category_id' => $this->category->id, 'month' => '2026-11', 'amount' => 2500];
    $this->actingAs($this->owner)->post(route('expense-budgets.store'), $payload)->assertRedirect();
    $this->post(route('expense-budgets.store'), $payload)->assertRedirect();
    expect(ExpenseBudget::count())->toBe(1)->and($this->budget->refresh()->amount)->toBe(2500)
        ->and(AuditEvent::where('action', 'expense_budget.saved')->count())->toBe(1)
        ->and(app(EffectiveLedger::class)->balance($this->family->id))->toBe(0);
    $this->post(route('expense-budgets.store'), [...$payload, 'month' => '2026-12', 'amount' => 0])->assertRedirect();
    expect(ExpenseBudget::count())->toBe(2);
    $this->get(route('expense-budgets.index', ['month' => '2026-12']))->assertInertia(fn (Assert $page) => $page
        ->where('month', '2026-12')->where('rows.0.budget', 0)->where('rows.0.remaining', 0));
});

it('creates reusable categories idempotently and reserves uncategorized', function () {
    $this->actingAs($this->owner)->post(route('expense-categories.store'), ['name' => 'Transport'])->assertRedirect();
    $this->post(route('expense-categories.store'), ['name' => ' transport '])->assertRedirect();
    expect(ExpenseCategory::count())->toBe(2)->and(AuditEvent::where('action', 'expense_category.created')->count())->toBe(1);
    $this->postJson(route('expense-categories.store'), ['name' => 'Uncategorized'])->assertUnprocessable();
    $this->postJson(route('expense-categories.store'), ['name' => ' '])->assertUnprocessable();
});

it('restricts budget management to admins and spending details to active officers of the current family', function () {
    $payload = ['expense_category_id' => $this->category->id, 'month' => '2026-11', 'amount' => 100];
    $this->actingAs($this->officer)->get(route('expense-budgets.index'))->assertOk();
    $this->postJson(route('expense-budgets.store'), $payload)->assertForbidden();
    $this->postJson(route('expense-categories.store'), ['name' => 'Other'])->assertForbidden();
    $member = User::factory()->member()->create(['family_id' => $this->family->id]);
    $this->actingAs($member);
    foreach (['index', 'details', 'preview'] as $route) {
        $this->getJson(route('expense-budgets.'.$route))->assertForbidden();
    }
    $foreign = ExpenseCategory::factory()->create();
    $this->actingAs($this->owner);
    $this->postJson(route('expense-budgets.store'), [...$payload, 'expense_category_id' => $foreign->id])->assertUnprocessable();
    foreach (['details', 'preview'] as $route) {
        $this->getJson(route('expense-budgets.'.$route, ['category' => $foreign->id]))->assertUnprocessable();
    }
    $this->getJson(route('expense-budgets.index', ['month' => '2026-99']))->assertUnprocessable();
    $this->postJson(route('expense-budgets.store'), [...$payload, 'amount' => -1])->assertUnprocessable();
    $this->postJson(route('expense-budgets.store'), [...$payload, 'amount' => 1.5])->assertUnprocessable();
});

it('reconciles month boundaries, uncategorized expenses, corrections and linked actual records', function () {
    $base = ['family_id' => $this->family->id, 'recorded_by' => $this->owner->id, 'expense_category_id' => $this->category->id];
    $first = Expense::factory()->create([...$base, 'amount' => 300, 'description' => 'First day support', 'spent_at' => '2026-11-01 00:00:00']);
    Expense::factory()->create([...$base, 'amount' => 200, 'spent_at' => '2026-11-30 23:59:59']);
    Expense::factory()->create([...$base, 'amount' => 900, 'spent_at' => '2026-10-31 23:59:59']);
    Expense::factory()->create([...$base, 'amount' => 900, 'spent_at' => '2026-12-01 00:00:00']);
    Expense::factory()->create([...$base, 'expense_category_id' => null, 'amount' => 50, 'spent_at' => '2026-11-12']);
    Expense::factory()->create(['amount' => 900, 'spent_at' => '2026-11-12']);
    $this->actingAs($this->owner)->get(route('expense-budgets.index'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.0.actual', 500)->where('rows.0.committed', 0)->where('rows.0.remaining', 500)
        ->where('rows.1.category', 'Uncategorized')->where('rows.1.actual', 50)->where('rows.1.budget', null));
    $this->get(route('expense-budgets.details', ['month' => '2026-11', 'category' => $this->category->id]))
        ->assertInertia(fn (Assert $page) => $page->where('total', 500)->has('entries.data', 2));
    $this->get(route('expenses.index', ['entry' => $first->id]))->assertInertia(fn (Assert $page) => $page
        ->has('expenses.data', 1)->where('expenses.data.0.category', 'Welfare')->where('expenses.data.0.id', $first->id));
    $replacement = app(CorrectExpense::class)->handle($first, $this->owner, 'Correct amount', 400, 'Corrected support', '2026-11-01');
    expect($replacement->expense_category_id)->toBe($this->category->id);
    $this->get(route('expense-budgets.index'))->assertInertia(fn (Assert $page) => $page->where('rows.0.actual', 600));
    app(ReverseExpense::class)->handle($replacement, $this->owner, 'Synthetic test cleanup');
    $this->get(route('expense-budgets.index'))->assertInertia(fn (Assert $page) => $page->where('rows.0.actual', 200));
});

it('moves an approved request from committed to actual exactly once and does not reopen it on reversal', function () {
    $plan = PlatformPlan::query()->firstOrCreate(['slug' => 'organization'], ['name' => 'Organization', 'price' => 10000, 'features' => [], 'is_active' => true]);
    $this->family->update(['platform_plan_id' => $plan->id, 'subscription_status' => 'active', 'expense_approval_settings' => ['enabled' => true, 'threshold' => 1, 'approvers' => 1]]);
    $payload = ['expense_category_id' => $this->category->id, 'amount' => 1500, 'description' => 'Approved welfare', 'spent_at' => '2026-11-13', 'idempotency_key' => (string) Str::uuid()];
    $this->actingAs($this->officer)->post(route('expense-requests.store'), $payload)->assertRedirect();
    $request = ExpenseRequest::sole();
    $this->get(route('expense-budgets.index'))->assertInertia(fn (Assert $page) => $page->where('rows.0.committed', 0));
    $this->actingAs($this->owner)->post(route('expense-requests.decide', $request), ['decision' => 'approved'])->assertRedirect();
    $this->get(route('expense-budgets.index'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.0.actual', 0)->where('rows.0.committed', 1500)->where('rows.0.remaining', -500));
    $this->get(route('expense-requests.index'))->assertInertia(fn (Assert $page) => $page
        ->where('requests.data.0.budget_advisory.projected', 1500)->where('requests.data.0.budget_advisory.over_by', 500));
    $this->get(route('expense-budgets.details', ['category' => $this->category->id, 'kind' => 'committed']))
        ->assertInertia(fn (Assert $page) => $page->where('total', 1500)->has('entries.data', 1)->where('entries.data.0.id', $request->id));
    expect(app(EffectiveLedger::class)->balance($this->family->id))->toBe(0);
    $this->post(route('expense-requests.pay', $request))->assertRedirect();
    $this->post(route('expense-requests.pay', $request))->assertRedirect();
    expect(Expense::count())->toBe(1)->and(Expense::sole()->expense_category_id)->toBe($this->category->id);
    $this->get(route('expense-budgets.index'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.0.actual', 1500)->where('rows.0.committed', 0));
    app(ReverseExpense::class)->handle(Expense::sole(), $this->owner, 'Synthetic test cleanup');
    $this->get(route('expense-budgets.index'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.0.actual', 0)->where('rows.0.committed', 0));
});

it('uses the stronger captured and current approval rule for commitments and excludes rejected requests', function () {
    $request = ExpenseRequest::factory()->create(['family_id' => $this->family->id, 'expense_category_id' => $this->category->id,
        'requested_by' => $this->officer->id, 'amount' => 500, 'required_approvals' => 1, 'status' => 'approved']);
    ExpenseApproval::create(['family_id' => $this->family->id, 'expense_request_id' => $request->id, 'user_id' => $this->owner->id, 'decision' => 'approved']);
    expect(app(ExpenseBudgetSpending::class)->committedQuery($this->family, '2026-11')->sum('amount'))->toBe(500);
    $this->family->update(['expense_approval_settings' => ['enabled' => true, 'threshold' => 1, 'approvers' => 2]]);
    expect(app(ExpenseBudgetSpending::class)->committedQuery($this->family, '2026-11')->sum('amount'))->toBe(0);
    $this->family->update(['expense_approval_settings' => null]);
    $request->update(['required_approvals' => 2]);
    expect(app(ExpenseBudgetSpending::class)->committedQuery($this->family, '2026-11')->sum('amount'))->toBe(0);
    $request->update(['required_approvals' => 1, 'status' => 'rejected']);
    expect(app(ExpenseBudgetSpending::class)->committedQuery($this->family, '2026-11')->sum('amount'))->toBe(0);
});

it('provides an advisory without blocking over-budget posting and binds replay to category', function () {
    $this->actingAs($this->officer)->getJson(route('expense-budgets.preview', ['category' => $this->category->id, 'month' => '2026-11', 'amount' => 1500]))
        ->assertOk()->assertJsonPath('advisory.over_by', 500)->assertJsonPath('advisory.projected', 1500);
    $this->getJson(route('expense-budgets.preview', ['month' => '2026-11', 'amount' => 1500]))->assertJsonPath('advisory', null);
    $payload = ['expense_category_id' => $this->category->id, 'amount' => 1500, 'description' => 'Advisory test', 'spent_at' => '2026-11-13', 'idempotency_key' => (string) Str::uuid()];
    $this->post(route('expenses.store'), $payload)->assertRedirect();
    $this->post(route('expenses.store'), $payload)->assertRedirect();
    $this->postJson(route('expenses.store'), [...$payload, 'expense_category_id' => null])->assertUnprocessable();
    expect(Expense::count())->toBe(1);
    $foreign = ExpenseCategory::factory()->create();
    $this->postJson(route('expenses.store'), [...$payload, 'expense_category_id' => $foreign->id, 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
});

it('removes budgets and categories during authorized family retention purge', function () {
    Storage::fake('local');
    ExpenseRequest::factory()->create(['family_id' => $this->family->id, 'expense_category_id' => $this->category->id]);
    $this->family->update(['archived_at' => now()->subDays(40), 'purge_after' => now()->subDay()]);
    (new PurgeArchivedFamily($this->family->id))->handle();
    expect(Family::find($this->family->id))->toBeNull()->and(ExpenseBudget::count())->toBe(0)->and(ExpenseCategory::count())->toBe(0);
});

it('retains budget and category information in a family archive export', function () {
    $this->family->update(['created_by' => $this->owner->id]);
    $this->actingAs($this->owner);
    $response = $this->get(route('family.archive.export', ['current_family' => $this->family->slug]));
    $response->assertOk();
    $data = new AssertableJsonString($response->streamedContent());
    $data->assertPath('family.expense_categories.0.name', 'Welfare');
    $data->assertPath('family.expense_budgets.0.amount', 1000);
});

it('does not count self approvals but retains independent approvals for a removed requester', function () {
    $request = ExpenseRequest::factory()->create(['family_id' => $this->family->id, 'expense_category_id' => $this->category->id,
        'requested_by' => $this->officer->id, 'amount' => 500, 'required_approvals' => 1]);
    ExpenseApproval::create(['family_id' => $this->family->id, 'expense_request_id' => $request->id, 'user_id' => $this->officer->id, 'decision' => 'approved']);
    expect(app(ExpenseBudgetSpending::class)->committedQuery($this->family, '2026-11')->sum('amount'))->toBe(0);
    $request->approvals()->delete();
    ExpenseApproval::create(['family_id' => $this->family->id, 'expense_request_id' => $request->id, 'user_id' => $this->owner->id, 'decision' => 'approved']);
    $request->update(['requested_by' => null]);
    expect(app(ExpenseBudgetSpending::class)->committedQuery($this->family, '2026-11')->sum('amount'))->toBe(500);
});
