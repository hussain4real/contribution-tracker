<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\GoalMeasure;
use App\Models\Family;
use App\Models\FamilyGoal;
use App\Support\EffectiveLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class FamilyGoalProgress
{
    public function __construct(private readonly EffectiveLedger $ledger) {}

    /** @return Builder<FamilyGoal> */
    public function query(Family $family): Builder
    {
        return FamilyGoal::query()->where('family_id', $family->id)->select('family_goals.*')
            ->selectSub($this->sum($this->ledger->paymentsForFamily($family->id)->toBase(), 'paid_at'), 'received_amount')
            ->selectSub($this->sum($this->ledger->expensesForFamily($family->id)->toBase(), 'spent_at'), 'expense_amount')
            ->selectSub($this->sum($this->ledger->adjustmentsForFamily($family->id)->toBase(), 'recorded_at'), 'adjustment_amount');
    }

    /** @param 'paid_at'|'spent_at'|'recorded_at' $date */
    private function sum(QueryBuilder $query, string $date): QueryBuilder
    {
        return $query->whereColumn($date, '>=', 'family_goals.starts_on')
            ->whereRaw('date('.$date.') <= family_goals.deadline')
            ->whereDate($date, '<=', now()->toDateString())->selectRaw('coalesce(sum(amount),0)');
    }

    /** @return array{id: int, name: string, measure: string, target_amount: int, starts_on: string, deadline: string, as_of: string, progress: int, remaining: int, percentage: float, status: string, received: int, expenses: int, adjustments: int, source_url: string|null} */
    public function present(FamilyGoal $goal, Family $family, bool $canTrace): array
    {
        $received = $goal->received_amount ?? 0;
        $expenses = $goal->expense_amount ?? 0;
        $adjustments = $goal->adjustment_amount ?? 0;
        $progress = $goal->measure === GoalMeasure::Contributions ? $received : $received + $adjustments - $expenses;
        $today = now()->startOfDay();
        $end = $goal->deadline->min($today)->toDateString();
        $status = match (true) {
            $goal->archived_at !== null => 'archived',
            $goal->starts_on->gt($today) => 'upcoming',
            $progress >= $goal->target_amount => 'reached',
            $goal->deadline->lt($today) => 'overdue',
            default => 'active',
        };

        return [
            'id' => $goal->id, 'name' => $goal->name, 'measure' => $goal->measure->value,
            'target_amount' => $goal->target_amount, 'starts_on' => $goal->starts_on->toDateString(),
            'deadline' => $goal->deadline->toDateString(), 'as_of' => $end,
            'progress' => $progress, 'remaining' => max(0, $goal->target_amount - $progress),
            'percentage' => (float) min(100, max(0, round($progress / $goal->target_amount * 100, 1))),
            'status' => $status, 'received' => $received, 'expenses' => $expenses, 'adjustments' => $adjustments,
            'source_url' => $canTrace && $goal->starts_on->lte($today)
                ? route('family.activity', ['current_family' => $family->slug, 'start_date' => $goal->starts_on->toDateString(), 'end_date' => $end, 'type' => $goal->measure === GoalMeasure::Contributions ? 'payment' : 'all']) : null,
        ];
    }
}
