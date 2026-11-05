<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreExpenseRequest;
use App\Models\Expense;
use App\Models\Family;
use Illuminate\Http\RedirectResponse;
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
    public function index(): Response
    {
        $this->authorize('viewAny', Expense::class);

        $user = $this->authUser();
        $family = $user->currentFamily ?? $user->family;

        abort_unless($family !== null, 403);

        $expenses = Expense::query()
            ->where('family_id', $family->id)
            ->with(['recorder', 'reversal'])
            ->latestFirst()
            ->latest('id')
            ->paginate(20)
            ->through(fn (Expense $expense) => [
                'id' => $expense->id,
                'amount' => $expense->amount,
                'description' => $expense->description,
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
    public function create(): Response
    {
        $this->authorize('create', Expense::class);

        $user = $this->authUser();
        $family = $user->currentFamily ?? $user->family;
        abort_unless($family !== null, 403);

        return Inertia::render('Expenses/Create', [
            'idempotencyKey' => (string) Str::uuid(),
            'idempotencyScope' => "expense-{$family->id}-{$user->id}",
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
                    || $existing->description !== $request->string('description')->toString()
                    || $existing->spent_at->toDateString() !== $request->date('spent_at')?->toDateString()) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This request was already used for a different expense. Check the expense list before starting a new expense.']);
                }

                return $existing->isReversed();
            }

            Expense::create([
                'family_id' => $family->id,
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
