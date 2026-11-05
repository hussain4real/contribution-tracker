<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Contribution;
use App\Support\EffectiveLedger;
use Illuminate\Support\Carbon;

class FamilyFinancialOverview
{
    public function __construct(private readonly EffectiveLedger $ledger) {}

    /** @return array{period: string, received: int, expenses: int, adjustments: int, opening_balance: int, closing_balance: int, outstanding: int} */
    public function forMonth(int $familyId, Carbon $start): array
    {
        $end = $start->copy()->addMonth()->toDateString();
        $from = $start->toDateString();
        $payments = $this->ledger->paymentsForFamily($familyId);
        $expenses = $this->ledger->expensesForFamily($familyId);
        $adjustments = $this->ledger->adjustmentsForFamily($familyId);
        $opening = (int) (clone $payments)->where('paid_at', '<', $from)->sum('amount')
            + (int) (clone $adjustments)->where('recorded_at', '<', $from)->sum('amount')
            - (int) (clone $expenses)->where('spent_at', '<', $from)->sum('amount');
        $received = (int) $payments->where('paid_at', '>=', $from)->where('paid_at', '<', $end)->sum('amount');
        $spent = (int) $expenses->where('spent_at', '>=', $from)->where('spent_at', '<', $end)->sum('amount');
        $adjusted = (int) $adjustments->where('recorded_at', '>=', $from)->where('recorded_at', '<', $end)->sum('amount');
        $contributions = Contribution::query()->where('family_id', $familyId)
            ->forMonth($start->year, $start->month)->withSum('payments as paid_amount', 'amount')->withCasts(['paid_amount' => 'integer'])->get();
        $outstanding = (int) $contributions->sum(
            function (Contribution $contribution): int {
                /** @var int|null $paid */
                $paid = $contribution->getAttribute('paid_amount');

                return max(0, $contribution->expected_amount - ($paid ?? 0));
            },
        );

        return [
            'period' => $start->format('Y-m'),
            'received' => $received,
            'expenses' => $spent,
            'adjustments' => $adjusted,
            'opening_balance' => $opening,
            'closing_balance' => $opening + $received + $adjusted - $spent,
            'outstanding' => $outstanding,
        ];
    }
}
