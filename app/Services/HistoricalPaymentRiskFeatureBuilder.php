<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Contribution;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\FinancialReversal;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class HistoricalPaymentRiskFeatureBuilder
{
    /**
     * Build every target in memory after a bounded set of eager-load queries.
     *
     * @param  EloquentCollection<int, Contribution>  $targets
     * @return array<int, array{membership_id: int, history_periods: int, timing_eligible: bool, cutoff_at: CarbonImmutable, features: array<string, float>}>
     */
    public function buildForFamily(Family $family, EloquentCollection $targets): array
    {
        if ($targets->isEmpty()) {
            return [];
        }

        if ($targets->contains(fn (Contribution $contribution): bool => $contribution->family_id !== $family->id)) {
            throw new InvalidArgumentException('Every payment-risk target must belong to the requested family.');
        }

        $userIds = $targets
            ->map(fn (Contribution $contribution): int => $contribution->user_id)
            ->unique()
            ->values()
            ->all();
        $memberships = $family->memberships()
            ->whereIn('user_id', $userIds)
            ->get(['id', 'family_id', 'user_id'])
            ->keyBy('user_id');
        $latestCutoff = $targets->max(fn (Contribution $contribution): ?string => $contribution->created_at?->toDateTimeString());

        if (! is_string($latestCutoff)) {
            throw new InvalidArgumentException('Payment-risk targets must have a recorded creation timestamp.');
        }

        $historyByUser = $this->historiesByUser($family, $userIds, $latestCutoff);
        $minimumLeadDays = $this->minimumLeadDays();
        $result = [];

        foreach ($targets as $target) {
            $membership = $memberships->get($target->user_id);

            if (! $membership instanceof FamilyMembership) {
                throw new InvalidArgumentException('The payment-risk target has no family membership.');
            }

            $cutoff = $target->created_at !== null
                ? CarbonImmutable::instance($target->created_at)
                : throw new InvalidArgumentException('Payment-risk targets must have a recorded creation timestamp.');
            $dueDate = CarbonImmutable::instance($target->due_date)->startOfDay();
            $timingEligible = $cutoff->startOfDay()->diffInDays($dueDate, false) >= $minimumLeadDays;
            $userHistory = $historyByUser[$target->user_id];
            $priorSnapshots = $userHistory
                ->filter(fn (Contribution $historical): bool => $this->isEligiblePriorPeriod($historical, $target, $cutoff, $minimumLeadDays))
                ->map(fn (Contribution $historical): array => $this->snapshotAtCutoff($historical, $cutoff))
                ->values();

            $features = $this->features($target, $cutoff, $priorSnapshots);

            $result[$target->id] = [
                'membership_id' => $membership->id,
                'history_periods' => $priorSnapshots->count(),
                'timing_eligible' => $timingEligible,
                'cutoff_at' => $cutoff,
                'features' => $features,
            ];
        }

        return $result;
    }

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, EloquentCollection<int, Contribution>>
     */
    private function historiesByUser(Family $family, array $userIds, string $latestCutoff): array
    {
        /** @var array<int, EloquentCollection<int, Contribution>> $histories */
        $histories = [];

        foreach ($userIds as $userId) {
            $histories[$userId] = new EloquentCollection;
        }

        $contributions = Contribution::query()
            ->where('family_id', $family->id)
            ->whereIn('user_id', $userIds)
            ->whereNotNull('due_date')
            ->where('created_at', '<=', $latestCutoff)
            ->with(['allPayments.batch.reversal'])
            ->orderBy('year')
            ->orderBy('month')
            ->orderBy('id')
            ->get();

        foreach ($contributions as $contribution) {
            $histories[$contribution->user_id]->add($contribution);
        }

        return $histories;
    }

    private function minimumLeadDays(): int
    {
        $minimumLeadDays = filter_var(
            config('payment-risk.minimum_days_before_due', 7),
            FILTER_VALIDATE_INT,
        );

        return is_int($minimumLeadDays) ? $minimumLeadDays : 7;
    }

    private function isEligiblePriorPeriod(
        Contribution $historical,
        Contribution $target,
        CarbonImmutable $cutoff,
        int $minimumLeadDays,
    ): bool {
        if ($historical->id === $target->id || $historical->created_at === null) {
            return false;
        }

        $createdAt = CarbonImmutable::instance($historical->created_at);
        $dueDate = CarbonImmutable::instance($historical->due_date)->startOfDay();

        return $createdAt->lessThan($cutoff)
            && ($historical->year < $target->year
                || ($historical->year === $target->year && $historical->month < $target->month))
            && $dueDate->endOfDay()->lessThanOrEqualTo($cutoff)
            && $createdAt->startOfDay()->diffInDays($dueDate, false) >= $minimumLeadDays;
    }

    /**
     * @return array{on_time: bool, partial: bool, settlement_delay_days: float|null, outstanding_at_cutoff: bool, outstanding_minor: int}
     */
    private function snapshotAtCutoff(Contribution $contribution, CarbonImmutable $cutoff): array
    {
        $dueHorizon = CarbonImmutable::instance($contribution->due_date)->endOfDay();
        $paymentsAtDue = $this->effectivePaymentsAt($contribution, $dueHorizon);
        $amountPaidAtDue = $this->totalPaid($paymentsAtDue);
        $outstanding = max(0, $contribution->expected_amount - $amountPaidAtDue);
        $settledAt = $this->settledAt($contribution, $cutoff);
        $outstandingAtCutoff = ! $settledAt instanceof CarbonImmutable;
        $settlementDelay = $settledAt instanceof CarbonImmutable
            ? (float) CarbonImmutable::instance($contribution->due_date)->startOfDay()->diffInDays($settledAt->startOfDay(), false)
            : null;

        return [
            'on_time' => $amountPaidAtDue >= $contribution->expected_amount,
            'partial' => $amountPaidAtDue > 0 && $amountPaidAtDue < $contribution->expected_amount,
            'settlement_delay_days' => $settlementDelay,
            'outstanding_at_cutoff' => $outstandingAtCutoff,
            'outstanding_minor' => $outstandingAtCutoff ? $outstanding * 100 : 0,
        ];
    }

    /** @return Collection<int, Payment> */
    private function effectivePaymentsAt(
        Contribution $contribution,
        CarbonImmutable $horizon,
        bool $strictlyBefore = false,
    ): Collection {
        return $contribution->allPayments
            ->filter(function (Payment $payment) use ($horizon, $strictlyBefore): bool {
                if ($payment->created_at === null) {
                    return false;
                }

                $recordedAt = CarbonImmutable::instance($payment->created_at);
                $isOutsideHorizon = $strictlyBefore
                    ? $recordedAt->greaterThanOrEqualTo($horizon)
                    : $recordedAt->greaterThan($horizon);

                if ($isOutsideHorizon) {
                    return false;
                }

                if ($payment->payment_batch_id === null) {
                    return true;
                }

                $reversal = $payment->batch?->reversal;

                if (! $reversal instanceof FinancialReversal) {
                    return true;
                }

                $reversedAt = CarbonImmutable::instance($reversal->created_at);

                return $strictlyBefore
                    ? $reversedAt->greaterThanOrEqualTo($horizon)
                    : $reversedAt->greaterThan($horizon);
            })
            ->sortBy([
                ['created_at', 'asc'],
                ['id', 'asc'],
            ])
            ->values();
    }

    private function settledAt(Contribution $contribution, CarbonImmutable $cutoff): ?CarbonImmutable
    {
        $total = 0;

        foreach ($this->effectivePaymentsAt($contribution, $cutoff, strictlyBefore: true) as $payment) {
            $total += $payment->amount;

            if ($total >= $contribution->expected_amount && $payment->created_at !== null) {
                return CarbonImmutable::instance($payment->created_at);
            }
        }

        return null;
    }

    /** @param Collection<int, Payment> $payments */
    private function totalPaid(Collection $payments): int
    {
        $total = 0;

        foreach ($payments as $payment) {
            $total += $payment->amount;
        }

        return $total;
    }

    /**
     * @param  Collection<int, array{on_time: bool, partial: bool, settlement_delay_days: float|null, outstanding_at_cutoff: bool, outstanding_minor: int}>  $history
     * @return array<string, float>
     */
    private function features(Contribution $target, CarbonImmutable $cutoff, Collection $history): array
    {
        $settlementDelays = $history->pluck('settlement_delay_days')
            ->filter(fn (mixed $delay): bool => is_float($delay) || is_int($delay))
            ->map(fn (mixed $delay): float => (float) $delay)
            ->values();
        $historyCount = $history->count();
        $month = $target->month;
        $outstandingAmountMinor = 0;

        foreach ($history as $snapshot) {
            $outstandingAmountMinor += $snapshot['outstanding_minor'];
        }

        return [
            'expected_amount_minor' => (float) ($target->expected_amount * 100),
            'days_until_due' => (float) $cutoff->startOfDay()->diffInDays(CarbonImmutable::instance($target->due_date)->startOfDay(), false),
            'calendar_month' => (float) $month,
            'calendar_quarter' => (float) (int) ceil($month / 3),
            'previous_mature_period_count' => (float) $historyCount,
            'prior_3_on_time_rate' => $this->onTimeRate($history->take(-3)),
            'prior_6_on_time_rate' => $this->onTimeRate($history->take(-6)),
            'prior_lifetime_on_time_rate' => $this->onTimeRate($history),
            'prior_partial_payment_rate' => $historyCount > 0
                ? (float) ($history->where('partial', true)->count() / $historyCount)
                : 0.0,
            'mean_recorded_settlement_delay_days' => $settlementDelays->isNotEmpty()
                ? (float) $settlementDelays->average()
                : 0.0,
            'median_recorded_settlement_delay_days' => $settlementDelays->isNotEmpty()
                ? (float) $settlementDelays->median()
                : 0.0,
            'prior_outstanding_count' => (float) $history->where('outstanding_at_cutoff', true)->count(),
            'prior_outstanding_amount_minor' => (float) $outstandingAmountMinor,
            'overdue_streak' => (float) $this->overdueStreak($history),
        ];
    }

    /**
     * @param  Collection<int, array{on_time: bool, partial: bool, settlement_delay_days: float|null, outstanding_at_cutoff: bool, outstanding_minor: int}>  $history
     */
    private function onTimeRate(Collection $history): float
    {
        return $history->isNotEmpty()
            ? (float) ($history->where('on_time', true)->count() / $history->count())
            : 0.0;
    }

    /**
     * @param  Collection<int, array{on_time: bool, partial: bool, settlement_delay_days: float|null, outstanding_at_cutoff: bool, outstanding_minor: int}>  $history
     */
    private function overdueStreak(Collection $history): int
    {
        $streak = 0;

        foreach ($history->reverse() as $snapshot) {
            if ($snapshot['on_time']) {
                break;
            }

            $streak++;
        }

        return $streak;
    }
}
