<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ExpenseBudgetQueryRequest;
use App\Http\Requests\StoreExpenseBudgetRequest;
use App\Http\Requests\StoreExpenseCategoryRequest;
use App\Models\Expense;
use App\Models\ExpenseBudget;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRequest;
use App\Models\Family;
use App\Services\ExpenseBudgetSpending;
use App\Support\AuditEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseBudgetController extends Controller
{
    private function family(): Family
    {
        $user = $this->authUser();
        $family = $user->currentFamily ?? $user->family;
        abort_unless($family instanceof Family && $user->canRecordPayments(), 403);

        return $family;
    }

    public function index(ExpenseBudgetQueryRequest $request, ExpenseBudgetSpending $spending): Response
    {
        $family = $this->family();
        $month = $request->string('month', now()->format('Y-m'))->toString();

        return Inertia::render('Expenses/Budgets', [
            'month' => $month, 'rows' => $spending->summary($family, $month),
            'can_manage' => $this->authUser()->isAdmin(),
        ]);
    }

    public function store(StoreExpenseBudgetRequest $request, AuditEventRecorder $audit): RedirectResponse
    {
        $family = $this->family();
        DB::transaction(function () use ($family, $request, $audit): void {
            Family::query()->lockForUpdate()->findOrFail($family->id);
            $budget = ExpenseBudget::query()->firstOrNew([
                'family_id' => $family->id, 'expense_category_id' => $request->integer('expense_category_id'),
                'month' => $request->string('month')->toString().'-01',
            ]);
            $before = $budget->exists ? $budget->only(['amount']) : [];
            $budget->amount = $request->integer('amount');
            if ($budget->isDirty()) {
                $budget->save();
                $audit->record($budget, 'expense_budget.saved', $family->id, before: $before, after: $budget->only(['amount', 'month', 'expense_category_id']));
            }
        });

        return to_route('expense-budgets.index', ['current_family' => $family->slug, 'month' => $request->string('month')->toString()])
            ->with('success', 'Monthly budget saved. Budget limits are advisory.');
    }

    public function storeCategory(StoreExpenseCategoryRequest $request, AuditEventRecorder $audit): RedirectResponse
    {
        $family = $this->family();
        DB::transaction(function () use ($family, $request, $audit): void {
            Family::query()->lockForUpdate()->findOrFail($family->id);
            $name = $request->string('name')->trim()->toString();
            $category = ExpenseCategory::query()->where('family_id', $family->id)->where(fn ($query) => $query->where('name', $name)->orWhereRaw('LOWER(name) = ?', [mb_strtolower($name)]))->first();
            if ($category === null) {
                $category = ExpenseCategory::query()->create(['family_id' => $family->id, 'name' => $name]);
                $audit->record($category, 'expense_category.created', $family->id, after: ['name' => $name]);
            }
        });

        return back()->with('success', 'Expense category is ready.');
    }

    public function preview(ExpenseBudgetQueryRequest $request, ExpenseBudgetSpending $spending): JsonResponse
    {
        return response()->json(['advisory' => $spending->advisory(
            $this->family(), $request->filled('category') ? $request->integer('category') : null,
            $request->string('month', now()->format('Y-m'))->toString(), $request->integer('amount'),
        )])->header('Cache-Control', 'private, no-store');
    }

    public function details(ExpenseBudgetQueryRequest $request, ExpenseBudgetSpending $spending): Response
    {
        $family = $this->family();
        $month = $request->string('month', now()->format('Y-m'))->toString();
        $categoryId = $request->filled('category') ? $request->integer('category') : null;
        $category = $categoryId === null ? null : ExpenseCategory::query()->where('family_id', $family->id)->findOrFail($categoryId);
        $kind = $request->string('kind', 'actual')->toString();
        $query = $kind === 'actual' ? $spending->actualQuery($family, $month) : $spending->committedQuery($family, $month);
        $query->where('expense_category_id', $categoryId);
        $total = (int) (clone $query)->sum('amount');
        $entries = $query->orderByDesc('spent_at')->orderByDesc('id')->paginate(20)->withQueryString()
            ->through(fn (Expense|ExpenseRequest $entry): array => [
                'id' => $entry->id, 'description' => $entry->description,
                'amount' => $entry->amount, 'spent_at' => $entry->spent_at->toDateString(),
                'url' => route($kind === 'actual' ? 'expenses.index' : 'expense-requests.index',
                    ['current_family' => $family->slug, 'entry' => $entry->id]),
            ]);

        return Inertia::render('Expenses/BudgetDetails', [
            'entries' => $entries, 'total' => $total, 'month' => $month,
            'category' => $category->name ?? 'Uncategorized', 'kind' => $kind,
        ]);
    }
}
