<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\Expense;
use App\Models\ExpenseBudget;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRequest;
use App\Models\ExpenseTemplate;
use App\Models\Family;
use App\Models\PlatformPlan;
use App\Models\User;
use App\Services\RecurringExpenseDrafts;
use App\Support\EffectiveLedger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(Carbon::parse('2027-01-30 12:00:00', 'UTC'));
    $plan = PlatformPlan::query()->firstOrCreate(['slug' => 'organization'], ['name' => 'Organization', 'price' => 10000, 'features' => [], 'is_active' => true]);
    $this->family = Family::factory()->create([
        'platform_plan_id' => $plan->id,
        'subscription_status' => 'active',
        'expense_approval_settings' => ['enabled' => true, 'threshold' => 1000, 'approvers' => 1],
    ]);
    $this->admin = User::factory()->admin()->create(['family_id' => $this->family->id]);
    $this->officer = User::factory()->financialSecretary()->create(['family_id' => $this->family->id]);
    $this->category = ExpenseCategory::factory()->create(['family_id' => $this->family->id, 'name' => 'Subscriptions']);
    $this->templateData = [
        'description' => 'Internet subscription', 'amount' => 1500, 'day_of_month' => 31,
        'expense_category_id' => $this->category->id, 'idempotency_key' => (string) Str::uuid(),
    ];
});

it('creates one monthly draft from a saved template and handles shorter months', function () {
    $this->actingAs($this->admin)->get(route('expense-templates.index'))->assertInertia(fn (Assert $page) => $page
        ->component('Expenses/Templates')->has('templates.data', 0)->where('categories.0.name', 'Subscriptions'));
    $this->postJson(route('expense-templates.store'), $this->templateData)->assertRedirect();
    $this->postJson(route('expense-templates.store'), $this->templateData)->assertRedirect();
    $this->postJson(route('expense-templates.store'), [...$this->templateData, 'amount' => 2000])->assertUnprocessable();
    $template = ExpenseTemplate::sole();
    expect($template->next_run_on)->toBe('2027-01-31');
    $this->get(route('expense-templates.index'))->assertInertia(fn (Assert $page) => $page
        ->where('templates.data.0.category', 'Subscriptions')->where('templates.data.0.next_run_on', '2027-01-31'));
    $this->artisan('expenses:generate-drafts')->assertSuccessful();
    expect(ExpenseRequest::count())->toBe(0);

    $this->travelTo(Carbon::parse('2027-01-31', 'UTC'));
    $this->artisan('expenses:generate-drafts')->expectsOutput('Generated 1 expense request draft(s).')->assertSuccessful();
    $this->artisan('expenses:generate-drafts')->expectsOutput('Generated 0 expense request draft(s).')->assertSuccessful();
    $draft = ExpenseRequest::sole();
    expect($draft->status)->toBe('draft')
        ->and($draft->requested_by)->toBeNull()
        ->and($draft->expense_template_id)->toBe($template->id)
        ->and($draft->expense_category_id)->toBe($this->category->id)
        ->and($draft->amount)->toBe(1500)
        ->and($draft->description)->toBe('Internet subscription')
        ->and($template->refresh()->next_run_on)->toBe('2027-02-28');

    // A recovered schedule pointer cannot duplicate an occurrence that already exists.
    $template->update(['next_run_on' => '2027-01-31']);
    $this->artisan('expenses:generate-drafts')->expectsOutput('Generated 0 expense request draft(s).')->assertSuccessful();
    $this->travelTo(Carbon::parse('2027-02-28', 'UTC'));
    $this->artisan('expenses:generate-drafts')->assertSuccessful();
    expect(ExpenseRequest::query()->orderBy('spent_at')->get()->map(fn (ExpenseRequest $request) => $request->spent_at->toDateString())->all())
        ->toBe(['2027-01-31', '2027-02-28'])
        ->and($template->refresh()->next_run_on)->toBe('2027-03-31')
        ->and(AuditEvent::query()->where('action', 'expense_request.drafted')->count())->toBe(2)
        ->and(Expense::count())->toBe(0);
});

it('pauses the schedule and resumes from its next occurrence without recreating a draft', function () {
    $template = ExpenseTemplate::factory()->create([
        'family_id' => $this->family->id, 'day_of_month' => 31, 'next_run_on' => '2027-01-31',
    ]);
    $selectedByScheduler = clone $template;
    $this->actingAs($this->admin)->postJson(route('expense-templates.pause', $template))->assertRedirect();
    $this->postJson(route('expense-templates.pause', $template))->assertRedirect();
    expect($template->refresh()->next_run_on)->toBeNull();
    expect(app(RecurringExpenseDrafts::class)->generate($selectedByScheduler, CarbonImmutable::parse('2027-01-31', 'UTC')))->toBeFalse();

    $this->travelTo(Carbon::parse('2027-02-12', 'UTC'));
    $this->artisan('expenses:generate-drafts')->assertSuccessful();
    expect(ExpenseRequest::count())->toBe(0);
    $this->postJson(route('expense-templates.resume', $template))->assertRedirect();
    expect($template->refresh()->next_run_on)->toBe('2027-02-28');
    $this->postJson(route('expense-templates.resume', $template))->assertRedirect();
    $this->travelTo(Carbon::parse('2027-02-28', 'UTC'));
    $this->artisan('expenses:generate-drafts')->assertSuccessful();
    $this->postJson(route('expense-templates.pause', $template))->assertRedirect();
    $this->postJson(route('expense-templates.resume', $template))->assertRedirect();
    expect($template->refresh()->next_run_on)->toBe('2027-03-31')
        ->and(ExpenseRequest::sole()->spent_at->toDateString())->toBe('2027-02-28')
        ->and(AuditEvent::query()->where('action', 'expense_template.paused')->count())->toBe(2)
        ->and(AuditEvent::query()->where('action', 'expense_template.resumed')->count())->toBe(2);
});

it('shows the draft budget warning and uses existing independent approvals and posting rules', function () {
    ExpenseTemplate::factory()->create([
        'family_id' => $this->family->id, 'expense_category_id' => $this->category->id,
        'amount' => 1500, 'day_of_month' => 30, 'next_run_on' => '2027-01-30',
    ]);
    ExpenseBudget::factory()->create([
        'family_id' => $this->family->id, 'expense_category_id' => $this->category->id, 'month' => '2027-01-01', 'amount' => 1000,
    ]);
    $this->artisan('expenses:generate-drafts')->assertSuccessful();
    $draft = ExpenseRequest::sole();
    $this->actingAs($this->officer)->get(route('expense-requests.index'))->assertInertia(fn (Assert $page) => $page
        ->where('requests.data.0.status', 'draft')->where('requests.data.0.can_submit_draft', true)
        ->where('requests.data.0.can_pay', false)->where('requests.data.0.can_decide', false)
        ->where('requests.data.0.budget_advisory.over_by', 500));
    $assertTotals = function (int $actual, int $committed): void {
        $this->get(route('expense-budgets.index', ['month' => '2027-01']))->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.actual', $actual)->where('rows.0.committed', $committed));
    };
    $assertTotals(0, 0);
    $this->postJson(route('expense-requests.pay', $draft))->assertUnprocessable();
    $this->postJson(route('expense-requests.decide', $draft), ['decision' => 'approved'])->assertUnprocessable();

    $this->postJson(route('expense-requests.submit', $draft))->assertRedirect();
    $this->postJson(route('expense-requests.submit', $draft))->assertRedirect();
    expect($draft->refresh()->required_approvals)->toBe(1)
        ->and($draft->requested_by)->toBe($this->officer->id)
        ->and(AuditEvent::query()->where('action', 'expense_request.submitted')->count())->toBe(1);
    $assertTotals(0, 0);
    $this->postJson(route('expense-requests.decide', $draft), ['decision' => 'approved'])->assertForbidden();
    $this->postJson(route('expense-requests.pay', $draft))->assertUnprocessable();

    $this->actingAs($this->admin)->postJson(route('expense-requests.decide', $draft), ['decision' => 'approved'])->assertRedirect();
    $assertTotals(0, 1500);
    $this->postJson(route('expense-requests.pay', $draft))->assertRedirect();
    $this->postJson(route('expense-requests.pay', $draft))->assertRedirect();
    $assertTotals(1500, 0);
    expect(Expense::sole()->expense_request_id)->toBe($draft->id)
        ->and(app(EffectiveLedger::class)->balance($this->family->id))->toBe(-1500);
});

it('isolates template management and draft submission by family, role, and plan', function () {
    $template = ExpenseTemplate::factory()->create(['family_id' => $this->family->id]);
    $other = User::factory()->admin()->create();
    $otherCategory = ExpenseCategory::factory()->create(['family_id' => $other->family_id]);
    $member = User::factory()->member()->create(['family_id' => $this->family->id]);

    $this->actingAs($this->officer)->get(route('expense-templates.index'))->assertForbidden();
    $this->postJson(route('expense-templates.store'), $this->templateData)->assertForbidden();
    $this->postJson(route('expense-templates.pause', $template))->assertForbidden();
    $this->actingAs($this->admin)->postJson(route('expense-templates.store'), [...$this->templateData, 'expense_category_id' => $otherCategory->id])->assertUnprocessable();
    $this->postJson(route('expense-templates.store'), [...$this->templateData, 'day_of_month' => 32])->assertUnprocessable();

    $this->artisan('expenses:generate-drafts', ['--family' => $other->family_id])->assertSuccessful();
    expect(ExpenseRequest::count())->toBe(0);
    $this->artisan('expenses:generate-drafts', ['--family' => $this->family->id, '--date' => '2027-01-30'])->assertSuccessful();
    $draft = ExpenseRequest::sole();
    $this->actingAs($member)->postJson(route('expense-requests.submit', $draft))->assertForbidden();
    $this->actingAs($other)->postJson(route('expense-templates.pause', $template))->assertForbidden();
    $this->postJson(route('expense-requests.submit', $draft))->assertForbidden();
    $this->family->update(['subscription_status' => 'cancelled']);
    $this->actingAs($this->officer)->postJson(route('expense-requests.submit', $draft))->assertForbidden();
    $this->travelTo(Carbon::parse('2027-02-01', 'UTC'));
    $this->artisan('expenses:generate-drafts')->assertSuccessful();
    $this->artisan('expenses:generate-drafts', ['--date' => '2027-02-30'])->assertExitCode(2);
    expect(ExpenseRequest::count())->toBe(1);
});

it('checks approval settings when the draft is submitted instead of when it is generated', function () {
    ExpenseTemplate::factory()->create(['family_id' => $this->family->id, 'amount' => 1500]);
    $this->artisan('expenses:generate-drafts')->assertSuccessful();
    $draft = ExpenseRequest::sole();
    $this->family->update(['expense_approval_settings' => ['enabled' => true, 'threshold' => 2000, 'approvers' => 2]]);
    $this->actingAs($this->officer)->postJson(route('expense-requests.submit', $draft))->assertRedirect();
    expect($draft->refresh()->status)->toBe('approved')->and($draft->required_approvals)->toBe(0);
});

it('retries an interrupted draft without advancing the schedule or duplicating the request', function () {
    $template = ExpenseTemplate::factory()->create(['family_id' => $this->family->id]);
    $scheduledDate = $template->next_run_on;
    $interrupted = true;
    Event::listen('eloquent.created: '.ExpenseRequest::class, function () use (&$interrupted): void {
        if ($interrupted) {
            $interrupted = false;
            throw new RuntimeException('Synthetic draft interruption');
        }
    });
    expect(fn () => Artisan::call('expenses:generate-drafts'))->toThrow(RuntimeException::class, 'Synthetic draft interruption');
    expect($template->refresh()->next_run_on)->toBe($scheduledDate)->and(ExpenseRequest::count())->toBe(0);
    $this->artisan('expenses:generate-drafts')->assertSuccessful();
    expect(ExpenseRequest::sole()->expense_template_id)->toBe($template->id);
});

it('applies and rolls back the template migration without rebuilding existing requests', function () {
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $connection->statement('PRAGMA foreign_keys=ON');
    $schema = $connection->getSchemaBuilder();
    $schema->create('families', fn (Blueprint $table) => $table->id());
    $schema->create('expense_categories', fn (Blueprint $table) => $table->id());
    $schema->create('expense_requests', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('amount');
    });
    $schema->create('expense_approvals', fn (Blueprint $table) => $table->foreignId('expense_request_id')->constrained()->cascadeOnDelete());
    $connection->table('expense_requests')->insert(['id' => 1, 'amount' => 1500]);
    $connection->table('expense_approvals')->insert(['expense_request_id' => 1]);
    $connection->statement('CREATE TRIGGER expense_request_migration_guard BEFORE DELETE ON expense_requests BEGIN SELECT RAISE(ABORT, "preserve requests"); END');
    $original = Schema::getFacadeRoot();
    Schema::swap($schema);
    try {
        $migration = require database_path('migrations/2026_11_21_174749_create_expense_templates_table.php');
        if (! $migration instanceof Migration) {
            throw new RuntimeException('Expected the recurring expense migration.');
        }
        (new ReflectionMethod($migration, 'up'))->invoke($migration);
        expect($schema->hasTable('expense_templates'))->toBeTrue()
            ->and($connection->table('expense_requests')->value('expense_template_id'))->toBeNull();
        (new ReflectionMethod($migration, 'down'))->invoke($migration);
        expect($schema->hasTable('expense_templates'))->toBeFalse()
            ->and($schema->hasColumn('expense_requests', 'template_month'))->toBeFalse();
        (new ReflectionMethod($migration, 'up'))->invoke($migration);
        expect($connection->table('expense_requests')->value('amount'))->toBe(1500)
            ->and($connection->table('expense_approvals')->count())->toBe(1)
            ->and($connection->select('PRAGMA foreign_key_check'))->toBe([])
            ->and($connection->selectOne("SELECT name FROM sqlite_master WHERE type='trigger' AND name='expense_request_migration_guard'"))->not->toBeNull();
    } finally {
        Schema::swap($original);
    }
});
