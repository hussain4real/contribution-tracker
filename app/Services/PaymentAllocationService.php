<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentSource;
use App\Jobs\SendPaymentReceipt;
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
use Illuminate\Validation\ValidationException;
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

    /** @param list<array{year: int, month: int, period: string, amount: int}>|null $expectedAllocations */
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
        ?array $expectedAllocations = null,
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
            $expectedAllocations,
        ): PaymentBatch {
            $lockedFamily = Family::query()->lockForUpdate()->findOrFail($family->id);
            $existing = PaymentBatch::query()->where('family_id', $family->id)
                ->where('idempotency_key', $idempotencyKey)->with('allocations')->first();
            if ($existing instanceof PaymentBatch) {
                if ($expectedAllocations !== null) {
                    $this->verifyReviewedReplay($existing, $member, $amount, $paidAt, $recordedBy, $method, $reference, $notes, $expectedAllocations);
                }

                return $existing;
            }
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

            if ($expectedAllocations !== null) {
                $actualAllocations = $batch->allocations()->with('contribution')->get()->map(function (Payment $payment): array {
                    $contribution = $payment->contribution;
                    if (! $contribution instanceof Contribution) {
                        throw new InvalidArgumentException('The payment allocation has no contribution.');
                    }

                    return ['year' => $contribution->year, 'month' => $contribution->month,
                        'period' => $contribution->period_label, 'amount' => $payment->amount];
                })->all();
                if ($actualAllocations !== $expectedAllocations) {
                    throw ValidationException::withMessages(['review' => 'Contribution balances changed. Review the payment again before recording it.']);
                }
            }

            if ($source !== PaymentSource::Backfill) {
                $batch->receiptDeliveries()->create(['channel' => 'mail', 'status' => 'queued']);
                $batch->receiptDeliveries()->create([
                    'channel' => 'whatsapp',
                    'status' => $member->hasVerifiedWhatsApp() ? 'queued' : 'unavailable',
                ]);
                SendPaymentReceipt::dispatch($batch->id, $member->id, 'mail');

                if ($member->hasVerifiedWhatsApp()) {
                    SendPaymentReceipt::dispatch($batch->id, $member->id, 'whatsapp');
                }
            }

            return $batch->load('allocations');
        }, 3);
    }

    /** @param list<array{year: int, month: int, period: string, amount: int}> $expectedAllocations */
    private function verifyReviewedReplay(PaymentBatch $batch, User $member, int $amount, DateTimeInterface|string $paidAt, User $recordedBy, PaymentMethod $method, ?string $reference, ?string $notes, array $expectedAllocations): void
    {
        $matches = $batch->membership?->user_id === $member->id && $batch->total_amount === $amount
            && $batch->paid_at->toDateString() === Carbon::parse($paidAt)->toDateString()
            && $batch->recorded_by === $recordedBy->id && $batch->method === $method
            && $batch->reference === $reference && $batch->notes === $notes;
        $actual = $batch->allocations()->with('contribution')->get()->map(function (Payment $payment): array {
            $contribution = $payment->contribution;
            if (! $contribution instanceof Contribution) {
                throw new InvalidArgumentException('The payment allocation has no contribution.');
            }

            return ['year' => $contribution->year, 'month' => $contribution->month,
                'period' => $contribution->period_label, 'amount' => $payment->amount];
        })->all();
        if (! $matches || $actual !== $expectedAllocations) {
            throw ValidationException::withMessages(['review' => 'This payment request key was already used for different details.']);
        }
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
        $cursor = Carbon::createFromDate($lastContribution->year, $lastContribution->month, 1)->startOfMonth()->addMonth();
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
