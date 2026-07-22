<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ReconciliationPeriodStatus;
use App\Enums\ReconciliationStatus;
use App\Models\BankTransaction;
use App\Models\ReconciliationPeriod;
use App\Models\User;
use App\Support\AuditEventRecorder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReconciliationStatusService
{
    public function __construct(private readonly AuditEventRecorder $audit) {}

    public function update(BankTransaction $transaction, ReconciliationStatus $status, User $actor, ?string $reason): BankTransaction
    {
        return DB::transaction(function () use ($transaction, $status, $actor, $reason): BankTransaction {
            $locked = BankTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            $closed = ReconciliationPeriod::query()
                ->where('family_id', $locked->family_id)
                ->where('status', ReconciliationPeriodStatus::Closed)
                ->whereDate('starts_at', '<=', $locked->transacted_at)
                ->whereDate('ends_at', '>=', $locked->transacted_at)
                ->exists();

            if ($closed) {
                throw new InvalidArgumentException('This transaction belongs to a closed reconciliation period.');
            }

            if ($status !== ReconciliationStatus::Matched && $locked->links()->exists()) {
                throw new InvalidArgumentException('Remove existing links before changing this transaction status.');
            }

            $before = ['status' => $locked->status->value, 'ignored_reason' => $locked->ignored_reason, 'disputed_reason' => $locked->disputed_reason];
            $locked->forceFill([
                'status' => $status,
                'ignored_reason' => $status === ReconciliationStatus::Ignored ? trim((string) $reason) : null,
                'disputed_reason' => $status === ReconciliationStatus::Disputed ? trim((string) $reason) : null,
            ])->save();
            $this->audit->record($locked, 'reconciliation.transaction.status_changed', $locked->family_id, $actor->id, before: $before, after: [
                'status' => $status->value,
                'reason' => $reason,
            ]);

            return $locked;
        }, attempts: 3);
    }
}
