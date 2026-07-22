<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Expense;
use App\Services\ReconciliationPeriodGuard;
use App\Support\AuditEventRecorder;

class ExpenseObserver
{
    public function __construct(
        private AuditEventRecorder $audit,
        private ReconciliationPeriodGuard $periodGuard,
    ) {}

    public function creating(Expense $expense): void
    {
        $this->periodGuard->ensureLedgerDateIsWritable($expense->family_id, $expense->spent_at);
    }

    public function created(Expense $expense): void
    {
        $this->audit->record(
            $expense,
            'expense.posted',
            $expense->family_id,
            $expense->recorded_by,
            after: [
                'amount' => $expense->amount,
                'description' => $expense->description,
                'spent_at' => $expense->spent_at->toDateString(),
            ],
        );
    }
}
