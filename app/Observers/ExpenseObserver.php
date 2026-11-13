<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\ExpenseGovernance;
use App\Services\ReconciliationPeriodGuard;
use App\Support\AuditEventRecorder;
use Illuminate\Validation\ValidationException;

class ExpenseObserver
{
    public function __construct(
        private AuditEventRecorder $audit,
        private ReconciliationPeriodGuard $periodGuard,
    ) {}

    public function creating(Expense $expense): void
    {
        if ($expense->expense_category_id !== null && ! ExpenseCategory::query()->where('family_id', $expense->family_id)->whereKey($expense->expense_category_id)->exists()) {
            throw ValidationException::withMessages(['expense_category_id' => 'Select an expense category belonging to this family.']);
        }
        app(ExpenseGovernance::class)->ensurePostingAllowed($expense);
        $this->periodGuard->ensureDateIsWritable($expense->family_id, $expense->spent_at);
    }

    public function created(Expense $expense): void
    {
        $this->audit->record(
            $expense,
            'expense.posted',
            $expense->family_id,
            $expense->recorded_by,
            after: [
                'expense_category_id' => $expense->expense_category_id,
                'amount' => $expense->amount,
                'description' => $expense->description,
                'spent_at' => $expense->spent_at->toDateString(),
            ],
        );
    }
}
