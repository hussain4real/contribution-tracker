<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreExpenseRequest;
use App\Models\Expense;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
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

        return Inertia::render('Expenses/Create');
    }

    /**
     * Store a newly created expense.
     */
    public function store(StoreExpenseRequest $request): RedirectResponse
    {
        $user = $this->user($request);
        $family = $user->currentFamily ?? $user->family;

        abort_unless($family !== null, 403);

        DB::transaction(function () use ($family, $request, $user): void {
            Expense::create([
                'family_id' => $family->id,
                'amount' => $request->integer('amount'),
                'description' => $request->string('description')->toString(),
                'spent_at' => $request->string('spent_at')->toString(),
                'recorded_by' => $user->id,
            ]);
        }, attempts: 3);

        return redirect()->route('expenses.index')
            ->with('success', 'Expense recorded successfully.');
    }
}
