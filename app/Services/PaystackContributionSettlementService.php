<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentSource;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Expense;
use App\Models\Family;
use App\Models\PaystackTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class PaystackContributionSettlementService
{
    public function __construct(
        private readonly PaymentAllocationService $paymentAllocationService,
    ) {}

    /** @param array<string, mixed> $providerData */
    public function settle(string $reference, array $providerData): PaystackTransaction
    {
        try {
            return DB::transaction(function () use ($reference, $providerData): PaystackTransaction {
                $transaction = PaystackTransaction::query()
                    ->where('reference', $reference)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($transaction->type !== TransactionType::Contribution) {
                    throw new RuntimeException('The Paystack transaction is not a contribution payment.');
                }

                if ($transaction->payment_batch_id !== null && $transaction->allocated_at !== null) {
                    return $transaction;
                }

                if ($transaction->status === TransactionStatus::Success) {
                    return $transaction;
                }

                if (($providerData['status'] ?? null) !== 'success') {
                    throw new RuntimeException('Paystack did not verify the charge as successful.');
                }

                $receivedAmountKobo = $this->integer($providerData['amount'] ?? null);

                if ($receivedAmountKobo !== $transaction->expectedGrossAmountKobo()) {
                    throw new RuntimeException('The verified Paystack amount does not match the initiated charge.');
                }

                $actualFeeKobo = $this->nullableInteger($providerData['fees'] ?? null);
                $effectiveFeeKobo = $actualFeeKobo
                    ?? $transaction->estimated_fee_kobo
                    ?? max(0, $receivedAmountKobo - $transaction->contributionAmountKobo());

                $transaction->forceFill([
                    'status' => TransactionStatus::Verified,
                    'verified_at' => now(),
                    'failed_at' => null,
                    'failure_reason' => null,
                    'paystack_response' => $providerData,
                    'gross_amount_kobo' => $receivedAmountKobo,
                    'actual_fee_kobo' => $actualFeeKobo,
                    'settled_amount_kobo' => max(0, $receivedAmountKobo - $effectiveFeeKobo),
                ])->save();

                $member = User::query()->findOrFail($transaction->user_id);
                $family = Family::query()->findOrFail($transaction->family_id);
                $metadata = $transaction->metadata ?? [];
                $paidAt = $providerData['paid_at'] ?? now()->toDateString();

                $batch = $this->paymentAllocationService->createBatch(
                    member: $member,
                    amount: $transaction->amount,
                    paidAt: is_scalar($paidAt) ? (string) $paidAt : now()->toDateString(),
                    recordedBy: $member,
                    notes: "Online payment via Paystack (Ref: {$transaction->reference})",
                    targetYear: $this->nullableInteger($metadata['target_year'] ?? null),
                    targetMonth: $this->nullableInteger($metadata['target_month'] ?? null),
                    family: $family,
                    method: PaymentMethod::Paystack,
                    source: PaymentSource::Paystack,
                    reference: $transaction->reference,
                    idempotencyKey: "paystack:{$transaction->reference}",
                );

                $feeExpense = $this->recordFeeShortfall($transaction, $member, $providerData);

                $transaction->forceFill([
                    'status' => TransactionStatus::Allocated,
                    'payment_batch_id' => $batch->id,
                    'fee_expense_id' => $feeExpense?->id,
                    'allocated_at' => now(),
                ])->save();

                return $transaction->fresh(['paymentBatch', 'feeExpense']) ?? $transaction;
            }, 5);
        } catch (Throwable $exception) {
            $failedTransaction = PaystackTransaction::query()
                ->where('reference', $reference)
                ->whereNull('allocated_at')
                ->first();

            $failedTransaction?->forceFill([
                'status' => TransactionStatus::Failed,
                'failed_at' => now(),
                'failure_reason' => mb_substr($exception->getMessage(), 0, 255),
            ])->save();

            throw $exception;
        }
    }

    /** @param array<string, mixed> $providerData */
    private function recordFeeShortfall(
        PaystackTransaction $transaction,
        User $member,
        array $providerData,
    ): ?Expense {
        if ($transaction->fee_expense_id !== null) {
            return Expense::query()->find($transaction->fee_expense_id);
        }

        $settledAmountKobo = $transaction->settled_amount_kobo ?? $transaction->expectedGrossAmountKobo();
        $shortfallKobo = $transaction->contributionAmountKobo() - $settledAmountKobo;

        if ($shortfallKobo <= 0) {
            return null;
        }

        $paidAt = $providerData['paid_at'] ?? now()->toDateString();

        return Expense::query()->create([
            'family_id' => $transaction->family_id,
            'amount' => intdiv($shortfallKobo + 99, 100),
            'description' => "Paystack processing fee shortfall for transaction {$transaction->reference}",
            'spent_at' => is_scalar($paidAt) ? (string) $paidAt : now()->toDateString(),
            'recorded_by' => $member->id,
        ]);
    }

    private function integer(mixed $value): int
    {
        if (! is_numeric($value)) {
            throw new RuntimeException('Paystack did not return a valid charge amount.');
        }

        return (int) $value;
    }

    private function nullableInteger(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
