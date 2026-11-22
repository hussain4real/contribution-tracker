<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreExpenseTemplateRequest;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRequest;
use App\Models\ExpenseTemplate;
use App\Models\Family;
use App\Services\ExpenseGovernance;
use App\Services\RecurringExpenseDrafts;
use App\Support\AuditEventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseTemplateController extends Controller
{
    public function __construct(
        private ExpenseGovernance $governance,
        private RecurringExpenseDrafts $drafts,
        private AuditEventRecorder $audit,
    ) {}

    public function index(): Response
    {
        $family = $this->adminFamily();
        $templates = ExpenseTemplate::query()->where('family_id', $family->id)->with('category:id,name')
            ->orderByDesc('id')->paginate(20)->through(fn (ExpenseTemplate $template): array => [
                'id' => $template->id,
                'description' => $template->description,
                'amount' => $template->amount,
                'category' => $template->category->name ?? 'Uncategorized',
                'day_of_month' => $template->day_of_month,
                'next_run_on' => $template->next_run_on,
            ]);

        return Inertia::render('Expenses/Templates', [
            'templates' => $templates,
            'categories' => ExpenseCategory::query()->where('family_id', $family->id)->orderBy('name')->get(['id', 'name']),
            'idempotencyKey' => (string) Str::uuid(),
            'idempotencyScope' => "expense-template-{$family->id}-{$this->authUser()->id}",
        ]);
    }

    public function store(StoreExpenseTemplateRequest $request): RedirectResponse
    {
        $family = $this->adminFamily();
        $attributes = [
            'description' => $request->string('description')->toString(),
            'amount' => $request->integer('amount'),
            'expense_category_id' => $request->filled('expense_category_id') ? $request->integer('expense_category_id') : null,
            'day_of_month' => $request->integer('day_of_month'),
        ];
        DB::transaction(function () use ($family, $request, $attributes): void {
            $this->lockEligibleFamily($family);
            $template = ExpenseTemplate::query()->firstOrCreate([
                'family_id' => $family->id, 'idempotency_key' => $request->string('idempotency_key')->toString(),
            ], [
                ...$attributes,
                'next_run_on' => $this->drafts->nextDate($attributes['day_of_month'], now('UTC')->toImmutable())->toDateString(),
            ]);
            if ($template->only(array_keys($attributes)) !== $attributes) {
                throw ValidationException::withMessages(['idempotency_key' => 'This request key was already used.']);
            }
            if ($template->wasRecentlyCreated) {
                $this->audit->record($template, 'expense_template.created', $family->id, after: $attributes);
            }
        }, 3);

        return back()->with('success', 'Monthly expense template saved.');
    }

    public function pause(ExpenseTemplate $expenseTemplate): RedirectResponse
    {
        return $this->setActive($expenseTemplate, false);
    }

    public function resume(ExpenseTemplate $expenseTemplate): RedirectResponse
    {
        return $this->setActive($expenseTemplate, true);
    }

    private function setActive(ExpenseTemplate $template, bool $active): RedirectResponse
    {
        $family = $this->adminFamily();
        abort_unless($template->family_id === $family->id, 403);
        DB::transaction(function () use ($family, $template, $active): void {
            $this->lockEligibleFamily($family);
            $locked = ExpenseTemplate::query()->lockForUpdate()->findOrFail($template->id);
            if (($locked->next_run_on !== null) === $active) {
                return;
            }

            $next = null;
            if ($active) {
                $date = $this->drafts->nextDate($locked->day_of_month, now('UTC')->toImmutable());
                if (ExpenseRequest::query()->where('family_id', $family->id)->where('expense_template_id', $locked->id)
                    ->where('template_month', $date->startOfMonth()->toDateString())->exists()) {
                    $date = $this->drafts->nextDate($locked->day_of_month, $date->startOfMonth()->addMonth());
                }
                $next = $date->toDateString();
            }
            $locked->update(['next_run_on' => $next]);
            $this->audit->record($locked, $active ? 'expense_template.resumed' : 'expense_template.paused', $family->id, after: ['next_run_on' => $next]);
        }, 3);

        return back()->with('success', $active ? 'Expense template resumed.' : 'Expense template paused.');
    }

    private function adminFamily(): Family
    {
        $user = $this->authUser();
        $family = $user->currentFamily ?? $user->family;
        abort_unless($family instanceof Family && $user->isAdmin() && $this->governance->supportsApprovals($family), 403);

        return $family;
    }

    private function lockEligibleFamily(Family $family): void
    {
        $locked = Family::query()->lockForUpdate()->findOrFail($family->id);
        abort_unless(! $locked->isArchived() && ! $locked->isSuspended() && $this->governance->supportsApprovals($locked), 403);
    }
}
