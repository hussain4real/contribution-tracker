<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Contribution;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Payment;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class PaymentAllocationService
{
    /**
     * Allocate a payment amount to contributions following the balance-first rule (FR-020).
     *
     * Any payment will first complete the oldest incomplete month before
     * registering payments for future months.
     *
     * @param  User  $member  The member making the payment
     * @param  int  $amount  Amount in kobo
     * @param  DateTimeInterface|string  $paidAt  When the payment was made
     * @param  User  $recordedBy  Who recorded the payment
     * @param  string|null  $notes  Optional notes
     * @param  int|null  $targetYear  Target year (optional)
     * @param  int|null  $targetMonth  Target month (optional)
     * @return Collection<int, Payment> Created payments
     */
    public function allocate(
        User $member,
        int $amount,
        DateTimeInterface|string $paidAt,
        User $recordedBy,
        ?string $notes = null,
        ?int $targetYear = null,
        ?int $targetMonth = null,
        ?Family $family = null,
    ): Collection {
        $family ??= $recordedBy->currentFamily ?? $recordedBy->family;

        if (! $family instanceof Family) {
            throw new InvalidArgumentException('A family context is required to allocate a payment.');
        }

        $membership = $member->membershipForFamily($family);

        if (! $membership instanceof FamilyMembership) {
            throw new InvalidArgumentException('The selected member does not belong to the active family.');
        }

        $payments = $this->newPaymentCollection();
        $remainingAmount = $amount;

        // Get or create contributions up to target month
        $contributions = $this->getContributionsToAllocate($member, $family, $membership, $targetYear, $targetMonth);

        foreach ($contributions as $contribution) {
            if ($remainingAmount <= 0) {
                break;
            }

            // Skip fully paid contributions
            if ($contribution->isPaid()) {
                continue;
            }

            // Calculate how much can be applied to this contribution
            $balance = $contribution->balance;
            $amountToApply = min($remainingAmount, $balance);

            if ($amountToApply > 0) {
                $payment = Payment::create([
                    'contribution_id' => $contribution->id,
                    'amount' => $amountToApply,
                    'paid_at' => $paidAt,
                    'recorded_by' => $recordedBy->id,
                    'notes' => $notes,
                ]);

                $payments->push($payment);
                $remainingAmount -= $amountToApply;
            }
        }

        // If there's still remaining amount, allocate to future months
        if ($remainingAmount > 0) {
            // Determine start point for future allocation
            // If target specified, start from there; otherwise start from next month after last contribution
            $startYear = $targetYear ?? now()->year;
            $startMonth = $targetMonth ?? now()->month;

            // If we had contributions, start from the month after the last one
            if ($contributions->isNotEmpty() && ! $targetYear) {
                $lastContribution = $contributions->last();
                $startDate = now()->setYear($lastContribution->year)->setMonth($lastContribution->month)->addMonth();
                $startYear = $startDate->year;
                $startMonth = $startDate->month;
            }

            $additionalPayments = $this->allocateToFutureMonths(
                $member,
                $family,
                $membership,
                $remainingAmount,
                $paidAt,
                $recordedBy,
                $notes,
                $startYear,
                $startMonth
            );

            $payments = $payments->merge($additionalPayments);
        }

        return $payments;
    }

    /**
     * Get contributions to allocate payments to, in chronological order.
     *
     * Following FR-020: Balance-first rule - allocate to oldest incomplete first.
     *
     * @return Collection<int, Contribution>
     */
    private function getContributionsToAllocate(
        User $member,
        Family $family,
        FamilyMembership $membership,
        ?int $targetYear,
        ?int $targetMonth,
    ): Collection {
        // Get all existing incomplete contributions for this member, sorted oldest first
        $contributions = Contribution::forUser($member->id)
            ->where('family_id', $family->id)
            ->incomplete()
            ->oldestFirst()
            ->get();

        // If target month specified, include it even if it doesn't exist yet
        if ($targetYear && $targetMonth) {
            $targetExists = $contributions->first(function ($c) use ($targetYear, $targetMonth) {
                return $c->year === $targetYear && $c->month === $targetMonth;
            });

            if (! $targetExists) {
                // Check if target contribution exists but is already paid
                $existingTarget = Contribution::forUser($member->id)
                    ->where('family_id', $family->id)
                    ->forMonth($targetYear, $targetMonth)
                    ->first();

                if (! $existingTarget) {
                    // Create the target month contribution
                    $targetContribution = $this->createContribution($member, $family, $membership, $targetYear, $targetMonth);
                    $contributions->push($targetContribution);
                }
            }
        }

        // If no incomplete contributions at all, create the current month
        if ($contributions->isEmpty()) {
            $currentYear = now()->year;
            $currentMonth = now()->month;

            $currentContribution = Contribution::forUser($member->id)
                ->where('family_id', $family->id)
                ->forMonth($currentYear, $currentMonth)
                ->first();

            if (! $currentContribution) {
                $currentContribution = $this->createContribution($member, $family, $membership, $currentYear, $currentMonth);
            }

            $contributions->push($currentContribution);
        }

        // Sort by year and month to ensure oldest first
        return $contributions->sortBy([
            ['year', 'asc'],
            ['month', 'asc'],
        ])->values();
    }

    /**
     * Create a contribution for a specific month.
     */
    private function createContribution(User $member, Family $family, FamilyMembership $membership, int $year, int $month): Contribution
    {
        $dueDay = $family->due_day ?? Contribution::DUE_DAY;

        return Contribution::create([
            'family_id' => $family->id,
            'user_id' => $member->id,
            'year' => $year,
            'month' => $month,
            'expected_amount' => $membership->monthlyAmount() ?? 0,
            'due_date' => Contribution::dueDateForMonth($year, $month, $dueDay),
        ]);
    }

    /**
     * Allocate remaining amount to future months.
     *
     * @return Collection<int, Payment>
     */
    private function allocateToFutureMonths(
        User $member,
        Family $family,
        FamilyMembership $membership,
        int $remainingAmount,
        DateTimeInterface|string $paidAt,
        User $recordedBy,
        ?string $notes,
        int $targetYear,
        int $targetMonth
    ): Collection {
        $payments = $this->newPaymentCollection();
        $targetDate = now()->setYear($targetYear)->setMonth($targetMonth)->startOfMonth();

        // Start from target month and work forward if needed
        $date = $targetDate;
        $maxAdvanceDate = now()->addMonths(6)->startOfMonth();

        while ($remainingAmount > 0 && $date->lte($maxAdvanceDate)) {
            $contribution = Contribution::forUser($member->id)
                ->where('family_id', $family->id)
                ->forMonth($date->year, $date->month)
                ->first();

            if (! $contribution) {
                $contribution = $this->createContribution($member, $family, $membership, $date->year, $date->month);
            }

            if (! $contribution->isPaid()) {
                $balance = $contribution->balance;
                $amountToApply = min($remainingAmount, $balance);

                if ($amountToApply > 0) {
                    $payment = Payment::create([
                        'contribution_id' => $contribution->id,
                        'amount' => $amountToApply,
                        'paid_at' => $paidAt,
                        'recorded_by' => $recordedBy->id,
                        'notes' => $notes,
                    ]);

                    $payments->push($payment);
                    $remainingAmount -= $amountToApply;
                }
            }

            $date = $date->addMonth();
        }

        return $payments;
    }

    /**
     * @return Collection<int, Payment>
     */
    private function newPaymentCollection(): Collection
    {
        return (new Payment)->newCollection();
    }
}
