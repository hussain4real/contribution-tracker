<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AiActionKind;
use App\Http\Requests\StoreExpenseRequest;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseDocument;
use App\Models\Family;
use App\Services\AiActionDrafts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseController extends Controller
{
    /**
     * Display a listing of all expenses.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Expense::class);

        $user = $this->authUser();
        $family = $user->currentFamily ?? $user->family;

        abort_unless($family !== null, 403);

        $expenses = Expense::query()
            ->where('family_id', $family->id)
            ->when($request->filled('entry'), fn ($query) => $query->whereKey($request->integer('entry')))
            ->with(['recorder', 'reversal', 'documents', 'category'])
            ->latestFirst()
            ->latest('id')
            ->paginate(20)
            ->through(fn (Expense $expense) => [
                'id' => $expense->id,
                'documents' => $user->canRecordPayments() ? $expense->documents->where('ready', true)
                    ->map(fn (ExpenseDocument $document): array => ['id' => $document->id, 'filename' => $document->filename])->values()->all() : [],
                'can_upload' => $user->canRecordPayments(),
                'amount' => $expense->amount,
                'description' => $expense->description,
                'category' => $expense->category->name ?? 'Uncategorized',
                'spent_at' => $expense->spent_at->toDateString(),
                'recorded_by' => $expense->recorder?->name,
                'created_at' => $expense->created_at?->toDateString(),
                'is_reversed' => $expense->isReversed(),
                'reversal_reason' => $expense->reversal?->reason,
                'can_reverse' => $user->can('delete', $expense) && ! $expense->isReversed(),
            ]);

        return Inertia::render('Expenses/Index', [
            'expenses' => $expenses,
            'can_create' => $user->canRecordPayments(),
        ]);
    }

    /**
     * Show the form for creating a new expense.
     */
    public function create(Request $request): Response
    {
        $this->authorize('create', Expense::class);

        $user = $this->authUser();
        $family = $user->currentFamily ?? $user->family;
        abort_unless($family !== null, 403);
        $draft = app(AiActionDrafts::class)->read($request, $user, $family, AiActionKind::Expense);

        return Inertia::render('Expenses/Create', [
            'aiDraft' => $draft['values'] ?? null,
            'categories' => ExpenseCategory::query()->where('family_id', $family->id)->orderBy('name')->get(['id', 'name']),
            'idempotencyKey' => $draft['key'] ?? (string) Str::uuid(),
            'idempotencyScope' => "expense-{$family->id}-{$user->id}".($draft ? "-{$draft['key']}" : ''),
        ]);
    }

    /**
     * Store a newly created expense.
     */
    public function store(StoreExpenseRequest $request): RedirectResponse
    {
        $user = $this->user($request);
        $family = $user->currentFamily ?? $user->family;

        abort_unless($family !== null, 403);

        $wasReversed = DB::transaction(function () use ($family, $request, $user): bool {
            Family::query()->lockForUpdate()->findOrFail($family->id);
            $key = $request->string('idempotency_key')->toString();
            $existing = Expense::query()->where('family_id', $family->id)->where('idempotency_key', $key)->first();
            if ($existing instanceof Expense) {
                if ($existing->recorded_by !== $user->id || $existing->amount !== $request->integer('amount')
                    || $existing->expense_category_id !== ($request->filled('expense_category_id') ? $request->integer('expense_category_id') : null)
                    || $existing->description !== $request->string('description')->toString()
                    || $existing->spent_at->toDateString() !== $request->date('spent_at')?->toDateString()) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This request was already used for a different expense. Check the expense list before starting a new expense.']);
                }

                return $existing->isReversed();
            }

            Expense::create([
                'family_id' => $family->id,
                'expense_category_id' => $request->filled('expense_category_id') ? $request->integer('expense_category_id') : null,
                'amount' => $request->integer('amount'),
                'description' => $request->string('description')->toString(),
                'spent_at' => $request->string('spent_at')->toString(),
                'recorded_by' => $user->id,
                'idempotency_key' => $key,
            ]);

            return false;
        }, attempts: 3);

        return redirect()->route('expenses.index')
            ->with('success', $wasReversed
                ? 'This expense was already recorded and later reversed.'
                : 'Expense recorded successfully.');
    }
}
