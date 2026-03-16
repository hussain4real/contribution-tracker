<?php

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

        $expenses = Expense::query()
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
                'created_at' => $expense->created_at->toDateString(),
            ]);

        return Inertia::render('Expenses/Index', [
            'expenses' => $expenses,
            'can_create' => $this->canCreate(),
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
        Expense::create([
            'amount' => $request->amount,
            'description' => $request->description,
            'spent_at' => $request->spent_at,
            'recorded_by' => $request->user()->id,
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

    /**
     * Check if the current user can create expenses.
     */
    private function canCreate(): bool
    {
        return request()->user()->canRecordPayments();
    }
}
