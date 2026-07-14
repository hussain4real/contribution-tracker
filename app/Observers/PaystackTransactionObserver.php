<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\PaystackTransaction;
use App\Support\AuditEventRecorder;

class PaystackTransactionObserver
{
    public function __construct(private readonly AuditEventRecorder $audit) {}

    public function created(PaystackTransaction $transaction): void
    {
        $this->audit->record(
            $transaction,
            'paystack.initiated',
            $transaction->family_id,
            $transaction->user_id,
            after: $this->snapshot($transaction),
        );
    }

    public function updated(PaystackTransaction $transaction): void
    {
        if (! $transaction->wasChanged([
            'status',
            'verified_at',
            'allocated_at',
            'failed_at',
            'payment_batch_id',
        ])) {
            return;
        }

        $this->audit->record(
            $transaction,
            'paystack.state_changed',
            $transaction->family_id,
            before: [
                'status' => $transaction->getRawOriginal('status'),
                'payment_batch_id' => $transaction->getRawOriginal('payment_batch_id'),
            ],
            after: $this->snapshot($transaction),
        );
    }

    /** @return array<string, mixed> */
    private function snapshot(PaystackTransaction $transaction): array
    {
        return [
            'reference' => $transaction->reference,
            'status' => $transaction->status->value,
            'amount' => $transaction->amount,
            'gross_amount_kobo' => $transaction->gross_amount_kobo,
            'payment_batch_id' => $transaction->payment_batch_id,
            'fee_expense_id' => $transaction->fee_expense_id,
            'verified_at' => $transaction->verified_at?->toIso8601String(),
            'allocated_at' => $transaction->allocated_at?->toIso8601String(),
            'failed_at' => $transaction->failed_at?->toIso8601String(),
            'failure_reason' => $transaction->failure_reason,
        ];
    }
}
