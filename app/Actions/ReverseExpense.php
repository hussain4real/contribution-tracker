<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Expense;
use App\Models\FinancialReversal;
use App\Models\User;
use App\Services\ReconciliationPeriodGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ReverseExpense
{
    public function __construct(private readonly ReconciliationPeriodGuard $periodGuard) {}

    public function handle(Expense $expense, User $actor, string $reason, ?Expense $replacement = null): FinancialReversal
    {
        return DB::transaction(function () use ($expense, $actor, $reason, $replacement): FinancialReversal {
            $lockedExpense = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            $existing = $lockedExpense->reversal()->first();

            if ($existing instanceof FinancialReversal) {
                return $existing;
            }

            if (trim($reason) === '') {
                throw new InvalidArgumentException('A reversal reason is required.');
            }

            $this->periodGuard->ensureDateIsWritable($lockedExpense->family_id, $lockedExpense->spent_at);

            if ($lockedExpense->reconciliationLinks()->exists()) {
                throw new InvalidArgumentException('Remove reconciliation links before reversing this expense.');
            }

            if ($replacement instanceof Expense && $replacement->family_id !== $lockedExpense->family_id) {
                throw new InvalidArgumentException('A replacement expense must belong to the same family.');
            }

            return FinancialReversal::query()->create([
                'family_id' => $lockedExpense->family_id,
                'reversible_type' => Expense::MORPH_TYPE,
                'reversible_id' => $lockedExpense->id,
                'replacement_type' => $replacement?->getMorphClass(),
                'replacement_id' => $replacement?->id,
                'reason' => trim($reason),
                'reversed_by' => $actor->id,
                'request_id' => $this->requestId(),
            ]);
        }, 3);
    }

    private function requestId(): string
    {
        $requestId = app()->bound('request-id') ? app('request-id') : null;

        return is_string($requestId) && Str::isUuid($requestId)
            ? $requestId
            : (string) Str::uuid();
    }
}
