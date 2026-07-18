<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Contribution;
use App\Models\Expense;
use App\Models\FundAdjustment;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;

class EffectiveLedger
{
    /** @return Builder<Payment> */
    public function paymentsForFamily(int $familyId): Builder
    {
        return Payment::query()
            ->effective()
            ->whereIn(
                'contribution_id',
                Contribution::query()->where('family_id', $familyId)->select('id'),
            );
    }

    /** @return Builder<Expense> */
    public function expensesForFamily(int $familyId): Builder
    {
        return Expense::query()->where('family_id', $familyId)->effective();
    }

    /** @return Builder<FundAdjustment> */
    public function adjustmentsForFamily(int $familyId): Builder
    {
        return FundAdjustment::query()->where('family_id', $familyId)->effective();
    }

    public function paymentsTotal(int $familyId): int
    {
        return (int) $this->paymentsForFamily($familyId)->sum('amount');
    }

    public function expensesTotal(int $familyId): int
    {
        return (int) $this->expensesForFamily($familyId)->sum('amount');
    }

    public function adjustmentsTotal(int $familyId): int
    {
        return (int) $this->adjustmentsForFamily($familyId)->sum('amount');
    }

    public function balance(int $familyId): int
    {
        return $this->paymentsTotal($familyId)
            + $this->adjustmentsTotal($familyId)
            - $this->expensesTotal($familyId);
    }
}
