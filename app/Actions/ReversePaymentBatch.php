<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\TransactionStatus;
use App\Models\FinancialReversal;
use App\Models\PaymentBatch;
use App\Models\PaystackTransaction;
use App\Models\User;
use App\Services\ReconciliationPeriodGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ReversePaymentBatch
{
    public function __construct(private readonly ReconciliationPeriodGuard $periodGuard) {}

    public function handle(
        PaymentBatch $batch,
        User $actor,
        string $reason,
        ?PaymentBatch $replacement = null,
    ): FinancialReversal {
        return DB::transaction(function () use ($batch, $actor, $reason, $replacement): FinancialReversal {
            $lockedBatch = PaymentBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $existing = $lockedBatch->reversal()->first();

            if ($existing instanceof FinancialReversal) {
                return $existing;
            }

            if (trim($reason) === '') {
                throw new InvalidArgumentException('A reversal reason is required.');
            }

            $this->periodGuard->ensureDateIsWritable($lockedBatch->family_id, $lockedBatch->paid_at);

            if ($lockedBatch->reconciliationLinks()->exists()) {
                throw new InvalidArgumentException('Remove reconciliation links before reversing this payment receipt.');
            }

            if ($replacement instanceof PaymentBatch && $replacement->family_id !== $lockedBatch->family_id) {
                throw new InvalidArgumentException('A replacement receipt must belong to the same family.');
            }

            $reversal = FinancialReversal::query()->create([
                'family_id' => $lockedBatch->family_id,
                'reversible_type' => PaymentBatch::MORPH_TYPE,
                'reversible_id' => $lockedBatch->id,
                'replacement_type' => $replacement?->getMorphClass(),
                'replacement_id' => $replacement?->id,
                'reason' => trim($reason),
                'reversed_by' => $actor->id,
                'request_id' => $this->requestId(),
            ]);

            $paystackTransaction = PaystackTransaction::query()
                ->where('payment_batch_id', $lockedBatch->id)
                ->first();

            $paystackTransaction?->forceFill(['status' => TransactionStatus::Reversed])->save();

            return $reversal;
        }, 3);
    }

    private function requestId(): string
    {
        $requestId = app()->bound('request-id') ? app('request-id') : null;

        return is_string($requestId) && Str::isUuid($requestId)
            ? $requestId
            : (string) Str::uuid();
    }
}
