<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ReverseExpense;
use App\Http\Requests\StoreFinancialReversalRequest;
use App\Models\Expense;
use Illuminate\Http\RedirectResponse;

class ExpenseReversalController extends Controller
{
    public function __invoke(
        StoreFinancialReversalRequest $request,
        Expense $expense,
        ReverseExpense $reverseExpense,
    ): RedirectResponse {
        $this->authorize('delete', $expense);
        $reverseExpense->handle($expense, $this->user($request), $request->string('reason')->toString());

        return redirect()->back()->with('success', 'Expense reversed. The original record remains in the audit trail.');
    }
}
