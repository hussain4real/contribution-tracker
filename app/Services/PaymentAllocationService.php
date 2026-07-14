<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentSource;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Payment;
use App\Models\PaymentBatch;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PaymentAllocationService
{
    /**
     * Compatibility entry point for callers that only need allocation rows.
     *
     * @return Collection<int, Payment>
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
        return $this->createBatch(
            member: $member,
            amount: $amount,
            paidAt: $paidAt,
            recordedBy: $recordedBy,
            notes: $notes,
            targetYear: $targetYear,
            targetMonth: $targetMonth,
            family: $family,
        )->allocations;
    }

    public function createBatch(
        User $member,
        int $amount,
        DateTimeInterface|string $paidAt,
        User $recordedBy,
        ?string $notes = null,
        ?int $targetYear = null,
        ?int $targetMonth = null,
        ?Family $family = null,
        PaymentMethod $method = PaymentMethod::Cash,
        PaymentSource $source = PaymentSource::Manual,
        ?string $reference = null,
        ?string $idempotencyKey = null,
        ?PaymentBatch $replaces = null,
    ): PaymentBatch {
        $family ??= $recordedBy->currentFamily ?? $recordedBy->family;

        if (! $family instanceof Family) {
            throw new InvalidArgumentException('A family context is required to allocate a payment.');
        }

        if ($amount < 1) {
            throw new InvalidArgumentException('The payment amount must be at least one whole currency unit.');
        }

        $idempotencyKey ??= (string) Str::uuid();

        return DB::transaction(function () use (
            $member,
            $amount,
            $paidAt,
            $recordedBy,
            $notes,
            $targetYear,
            $targetMonth,
            $family,
            $method,
            $source,
            $reference,
            $idempotencyKey,
            $replaces,
        ): PaymentBatch {
            $existing = PaymentBatch::query()
                ->where('family_id', $family->id)
                ->where('idempotency_key', $idempotencyKey)
                ->with('allocations')
                ->first();

            if ($existing instanceof PaymentBatch) {
                return $existing;
            }

            $lockedFamily = Family::query()->lockForUpdate()->findOrFail($family->id);
            $membership = FamilyMembership::query()
                ->where('family_id', $lockedFamily->id)
                ->where('user_id', $member->id)
                ->active()
                ->lockForUpdate()
                ->first();

            if (! $membership instanceof FamilyMembership) {
                throw new InvalidArgumentException('The selected member does not belong to the active family.');
            }

            if ($replaces instanceof PaymentBatch
                && ($replaces->family_id !== $lockedFamily->id
                    || $replaces->family_membership_id !== $membership->id)) {
                throw new InvalidArgumentException('A corrected receipt must replace a receipt for the same family membership.');
            }

            $maximumReceiptNumber = PaymentBatch::query()
                ->where('family_id', $lockedFamily->id)
                ->max('receipt_number');
            $nextReceiptNumber = is_numeric($maximumReceiptNumber)
                ? ((int) $maximumReceiptNumber) + 1
                : 1;

            $batch = PaymentBatch::query()->create([
                'family_id' => $lockedFamily->id,
                'family_membership_id' => $membership->id,
                'member_name' => $membership->displayName(),
                'total_amount' => $amount,
                'paid_at' => $paidAt,
                'method' => $method,
                'source' => $source,
                'reference' => $reference,
                'recorded_by' => $recordedBy->id,
                'notes' => $notes,
                'receipt_number' => $nextReceiptNumber,
                'idempotency_key' => $idempotencyKey,
            ]);

            $remainingAmount = $this->allocateToContributions(
                batch: $batch,
                member: $member,
                family: $lockedFamily,
                membership: $membership,
                amount: $amount,
                paidAt: $paidAt,
                recordedBy: $recordedBy,
                notes: $notes,
                targetYear: $targetYear,
                targetMonth: $targetMonth,
                replaces: $replaces,
            );

            if ($remainingAmount !== 0) {
                throw new InvalidArgumentException('The payment exceeds the six-month advance-payment limit.');
            }

            return $batch->load('allocations');
        }, 3);
    }

    private function allocateToContributions(
        PaymentBatch $batch,
        User $member,
        Family $family,
        FamilyMembership $membership,
        int $amount,
        DateTimeInterface|string $paidAt,
        User $recordedBy,
        ?string $notes,
        ?int $targetYear,
        ?int $targetMonth,
        ?PaymentBatch $replaces,
    ): int {
        $remainingAmount = $amount;
        $contributions = Contribution::query()
            ->forUser($member)
            ->where('family_id', $family->id)
            ->where(function ($query) use ($replaces): void {
                $query->incomplete();

                if ($replaces instanceof PaymentBatch) {
                    $query->orWhereIn(
                        'id',
                        Payment::query()
                            ->where('payment_batch_id', $replaces->id)
                            ->select('contribution_id'),
                    );
                }
            })
            ->oldestFirst()
            ->lockForUpdate()
            ->get();

        if ($targetYear !== null && $targetMonth !== null) {
            $target = $this->findOrCreateContribution($member, $family, $membership, $targetYear, $targetMonth);

            if (! $target->isPaid() && ! $contributions->contains('id', $target->id)) {
                $contributions->push($target);
            }
        }

        if ($contributions->isEmpty()) {
            $contributions->push($this->findOrCreateContribution(
                $member,
                $family,
                $membership,
                $targetYear ?? now()->year,
                $targetMonth ?? now()->month,
            ));
        }

        $contributions = $contributions
            ->sortBy(fn (Contribution $contribution): int => ($contribution->year * 100) + $contribution->month)
            ->values();

        foreach ($contributions as $contribution) {
            $remainingAmount = $this->allocateLine(
                $batch,
                $contribution,
                $remainingAmount,
                $paidAt,
                $recordedBy,
                $notes,
                $replaces,
            );

            if ($remainingAmount === 0) {
                return 0;
            }
        }

        $lastContribution = $contributions->reverse()->firstOrFail();
        $cursor = Carbon::createFromDate($lastContribution->year, $lastContribution->month, 1)->addMonth();
        $maximumAdvanceDate = now()->addMonths(6)->startOfMonth();

        while ($remainingAmount > 0 && $cursor->lte($maximumAdvanceDate)) {
            $contribution = $this->findOrCreateContribution(
                $member,
                $family,
                $membership,
                $cursor->year,
                $cursor->month,
            );
            $remainingAmount = $this->allocateLine(
                $batch,
                $contribution,
                $remainingAmount,
                $paidAt,
                $recordedBy,
                $notes,
                $replaces,
            );
            $cursor->addMonth();
        }

        return $remainingAmount;
    }

    private function allocateLine(
        PaymentBatch $batch,
        Contribution $contribution,
        int $remainingAmount,
        DateTimeInterface|string $paidAt,
        User $recordedBy,
        ?string $notes,
        ?PaymentBatch $replaces,
    ): int {
        $contribution->unsetRelation('payments');
        $replacedAmount = $replaces instanceof PaymentBatch
            ? (int) $replaces->allocations()->where('contribution_id', $contribution->id)->sum('amount')
            : 0;
        $amountToApply = min($remainingAmount, $contribution->balance + $replacedAmount);

        if ($amountToApply <= 0) {
            return $remainingAmount;
        }

        Payment::query()->create([
            'contribution_id' => $contribution->id,
            'payment_batch_id' => $batch->id,
            'amount' => $amountToApply,
            'paid_at' => $paidAt,
            'recorded_by' => $recordedBy->id,
            'notes' => $notes,
        ]);

        return $remainingAmount - $amountToApply;
    }

    private function findOrCreateContribution(
        User $member,
        Family $family,
        FamilyMembership $membership,
        int $year,
        int $month,
    ): Contribution {
        $contribution = Contribution::query()
            ->forUser($member)
            ->where('family_id', $family->id)
            ->forMonth($year, $month)
            ->lockForUpdate()
            ->first();

        if ($contribution instanceof Contribution) {
            return $contribution;
        }

        $snapshot = $membership->contributionCategorySnapshot($year, $month);
        $expectedAmount = $snapshot['category_amount'];

        if ($expectedAmount === null || $expectedAmount < 1) {
            throw new InvalidArgumentException('The member has no contribution category for this period.');
        }

        return Contribution::query()->create([
            'family_id' => $family->id,
            'user_id' => $member->id,
            'year' => $year,
            'month' => $month,
            'expected_amount' => $expectedAmount,
            'due_date' => Contribution::dueDateForMonth($year, $month, $family->due_day ?? Contribution::DUE_DAY),
            ...$snapshot,
        ]);
    }
}
