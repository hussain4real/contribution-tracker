<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\PaymentMethod;
use App\Enums\PaymentSource;
use App\Models\PaymentBatch;
use App\Models\User;
use App\Services\PaymentAllocationService;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CorrectPaymentBatch
{
    public function __construct(
        private readonly PaymentAllocationService $allocationService,
        private readonly ReversePaymentBatch $reversePaymentBatch,
    ) {}

    public function handle(
        PaymentBatch $original,
        User $actor,
        string $reason,
        int $amount,
        DateTimeInterface|string $paidAt,
        PaymentMethod $method,
        ?string $reference = null,
        ?string $notes = null,
        ?int $targetYear = null,
        ?int $targetMonth = null,
        ?string $idempotencyKey = null,
    ): PaymentBatch {
        return DB::transaction(function () use (
            $original,
            $actor,
            $reason,
            $amount,
            $paidAt,
            $method,
            $reference,
            $notes,
            $targetYear,
            $targetMonth,
            $idempotencyKey,
        ): PaymentBatch {
            $original->loadMissing(['family', 'membership.user']);
            $member = $original->membership?->user;

            if (! $member instanceof User) {
                throw new InvalidArgumentException('The original receipt has no member available for correction.');
            }

            $replacement = $this->allocationService->createBatch(
                member: $member,
                amount: $amount,
                paidAt: $paidAt,
                recordedBy: $actor,
                notes: $notes,
                targetYear: $targetYear,
                targetMonth: $targetMonth,
                family: $original->family,
                method: $method,
                source: PaymentSource::Correction,
                reference: $reference,
                idempotencyKey: $idempotencyKey,
                replaces: $original,
            );

            $this->reversePaymentBatch->handle($original, $actor, $reason, $replacement);

            return $replacement;
        }, 3);
    }
}
