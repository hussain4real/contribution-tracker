<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Contribution;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\User;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;

class PaymentAllocationPreview
{
    /**
     * @return array{allocations: list<array{year: int, month: int, period: string, amount: int}>, outstanding: int, advance: int}
     */
    public function preview(User $member, Family $family, FamilyMembership $membership, int $amount, ?int $targetYear = null, ?int $targetMonth = null): array
    {
        if ($amount < 1) {
            throw new InvalidArgumentException('The payment amount must be at least one whole currency unit.');
        }

        $existing = Contribution::query()->forUser($member)->where('family_id', $family->id)
            ->with('payments')->get()->keyBy(fn (Contribution $contribution): string => $this->periodKey($contribution->year, $contribution->month));
        $outstanding = (int) $existing->sum(fn (Contribution $contribution): int => $contribution->balance);
        $pending = $existing->filter(fn (Contribution $contribution): bool => $contribution->balance > 0)->values();

        $resolve = function (int $year, int $month) use ($existing, $membership): Contribution {
            $key = $this->periodKey($year, $month);
            $contribution = $existing->get($key);
            if ($contribution instanceof Contribution) {
                return $contribution;
            }
            $snapshot = $membership->contributionCategorySnapshot($year, $month);
            $expected = $snapshot['category_amount'];
            if ($expected === null || $expected < 1) {
                throw new InvalidArgumentException('The member has no contribution category for this period.');
            }
            $contribution = new Contribution(['year' => $year, 'month' => $month, 'expected_amount' => $expected]);
            $contribution->setRelation('payments', $contribution->newCollection());
            $existing->put($key, $contribution);

            return $contribution;
        };

        if ($targetYear !== null && $targetMonth !== null) {
            $target = $resolve($targetYear, $targetMonth);
            if ($target->balance > 0 && ! $pending->contains(fn (Contribution $item): bool => $item->year === $targetYear && $item->month === $targetMonth)) {
                $pending->push($target);
            }
        }
        if ($pending->isEmpty()) {
            $pending->push($resolve($targetYear ?? now()->year, $targetMonth ?? now()->month));
        }
        $pending = $pending->sortBy(fn (Contribution $contribution): int => $contribution->year * 100 + $contribution->month)->values();
        $remaining = $amount;
        $allocations = [];
        $apply = function (Contribution $contribution, int $remaining) use (&$allocations): int {
            $applied = min($remaining, $contribution->balance);
            if ($applied > 0) {
                $allocations[] = ['year' => $contribution->year, 'month' => $contribution->month, 'period' => $contribution->period_label, 'amount' => $applied];
            }

            return $remaining - $applied;
        };
        foreach ($pending as $contribution) {
            $remaining = $apply($contribution, $remaining);
            if ($remaining === 0) {
                break;
            }
        }
        if ($remaining > 0) {
            $last = $pending->last() ?? throw new LogicException('A pending contribution is required for allocation.');
            $cursor = Carbon::createFromDate($last->year, $last->month, 1)->startOfMonth()->addMonth();
            $maximum = now()->startOfMonth()->addMonths(6);
            while ($cursor->lte($maximum)) {
                $remaining = $apply($resolve($cursor->year, $cursor->month), $remaining);
                if ($remaining === 0) {
                    break;
                }
                $cursor->addMonth();
            }
        }
        if ($remaining !== 0) {
            throw new InvalidArgumentException('The payment exceeds the six-month advance-payment limit.');
        }

        return ['allocations' => $allocations, 'outstanding' => $outstanding, 'advance' => max(0, $amount - $outstanding)];
    }

    private function periodKey(int $year, int $month): string
    {
        return sprintf('%04d-%02d', $year, $month);
    }
}
