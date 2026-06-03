<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreExpenseRequest;
use App\Models\Expense;
use Illuminate\Http\RedirectResponse;
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

        $expenses = Expense::query()
            ->where('family_id', $user->family_id)
            ->with('recorder')
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

        Expense::create([
            'family_id' => $user->family_id,
            'amount' => $request->integer('amount'),
            'description' => $request->string('description')->toString(),
            'spent_at' => $request->string('spent_at')->toString(),
            'recorded_by' => $user->id,
        ]);

        return redirect()->route('expenses.index')
            ->with('success', 'Expense recorded successfully.');
    }

    /**
     * Remove the specified expense.
     */
    public function destroy(Expense $expense): RedirectResponse
    {
        $this->authorize('delete', $expense);

        $expense->delete();

        return redirect()->route('expenses.index')
            ->with('success', 'Expense has been deleted.');
    }
}
