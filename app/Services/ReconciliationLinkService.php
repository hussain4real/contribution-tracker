<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BankTransactionDirection;
use App\Enums\ReconciliationStatus;
use App\Models\BankTransaction;
use App\Models\Expense;
use App\Models\FundAdjustment;
use App\Models\PaymentBatch;
use App\Models\PaystackTransaction;
use App\Models\ProviderSettlementGroup;
use App\Models\ReconciliationLink;
use App\Models\User;
use App\Support\AuditEventRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReconciliationLinkService
{
    /** @var array<string, class-string<Model>> */
    private const TARGETS = [
        PaymentBatch::MORPH_TYPE => PaymentBatch::class,
        Expense::MORPH_TYPE => Expense::class,
        FundAdjustment::MORPH_TYPE => FundAdjustment::class,
        ProviderSettlementGroup::MORPH_TYPE => ProviderSettlementGroup::class,
    ];

    public function __construct(
        private readonly AuditEventRecorder $audit,
        private readonly ReconciliationPeriodGuard $periodGuard,
    ) {}

    public function link(
        BankTransaction $bankTransaction,
        string $targetType,
        int $targetId,
        int $amount,
        User $actor,
        ?string $notes = null,
    ): ReconciliationLink {
        if ($amount < 1) {
            throw new InvalidArgumentException('The linked amount must be greater than zero.');
        }

        return DB::transaction(function () use ($bankTransaction, $targetType, $targetId, $amount, $actor, $notes): ReconciliationLink {
            $transaction = BankTransaction::query()->lockForUpdate()->findOrFail($bankTransaction->id);
            $this->ensurePeriodIsOpen($transaction);
            $target = $this->target($targetType, $targetId, $transaction->family_id, true);
            $this->ensureDirectionMatches($transaction, $target);

            if ($amount > $transaction->remainingAmount()) {
                throw new InvalidArgumentException('The linked amount exceeds the unmatched bank transaction amount.');
            }

            $targetRemaining = $this->targetAmount($target) - (int) ReconciliationLink::query()
                ->where('reconcilable_type', $target->getMorphClass())
                ->where('reconcilable_id', $target->getKey())
                ->sum('amount');

            if ($amount > $targetRemaining) {
                throw new InvalidArgumentException('The linked amount exceeds the remaining ledger amount.');
            }

            $link = ReconciliationLink::query()->create([
                'family_id' => $transaction->family_id,
                'bank_transaction_id' => $transaction->id,
                'reconcilable_type' => $target->getMorphClass(),
                'reconcilable_id' => $target->getKey(),
                'amount' => $amount,
                'created_by' => $actor->id,
                'notes' => $notes,
            ]);

            $this->refreshStatus($transaction);
            $this->audit->record($link, 'reconciliation.link.created', $transaction->family_id, $actor->id, after: [
                'bank_transaction_id' => $transaction->id,
                'target_type' => $target->getMorphClass(),
                'target_id' => $target->getKey(),
                'amount' => $amount,
            ]);

            return $link;
        }, attempts: 3);
    }

    public function unlink(ReconciliationLink $link, User $actor): void
    {
        DB::transaction(function () use ($link, $actor): void {
            $lockedLink = ReconciliationLink::query()->lockForUpdate()->findOrFail($link->id);
            $transaction = BankTransaction::query()->lockForUpdate()->findOrFail($lockedLink->bank_transaction_id);
            $this->ensurePeriodIsOpen($transaction);
            $before = $lockedLink->only(['bank_transaction_id', 'reconcilable_type', 'reconcilable_id', 'amount']);
            $this->audit->record($lockedLink, 'reconciliation.link.removed', $transaction->family_id, $actor->id, before: $before);
            $lockedLink->delete();
            $this->refreshStatus($transaction);
        }, attempts: 3);
    }

    public function target(string $type, int $id, int $familyId, bool $lock = false): Model
    {
        $class = self::TARGETS[$type] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException('Unsupported reconciliation target.');
        }

        $query = match ($class) {
            PaymentBatch::class => PaymentBatch::query()->effective()->where('family_id', $familyId),
            Expense::class => Expense::query()->effective()->where('family_id', $familyId),
            FundAdjustment::class => FundAdjustment::query()->effective()->where('family_id', $familyId),
            ProviderSettlementGroup::class => ProviderSettlementGroup::query()->where('family_id', $familyId),
        };

        if ($lock) {
            $query->lockForUpdate();
        }

        $target = $query->find($id);

        if (! $target instanceof Model) {
            throw new InvalidArgumentException('The reconciliation target is unavailable for this family.');
        }

        if ($target instanceof PaymentBatch) {
            $paystackTransaction = PaystackTransaction::query()
                ->where('payment_batch_id', $target->id)
                ->when($lock, fn ($query) => $query->lockForUpdate())
                ->first();

            if ($paystackTransaction?->settlementItem()->exists()) {
                throw new InvalidArgumentException('A settled Paystack receipt must be reconciled through its settlement group.');
            }
        }

        return $target;
    }

    public function targetAmount(Model $target): int
    {
        return match (true) {
            $target instanceof PaymentBatch => $target->total_amount,
            $target instanceof Expense => $target->amount,
            $target instanceof FundAdjustment => abs($target->amount),
            $target instanceof ProviderSettlementGroup => $target->net_amount,
            default => throw new InvalidArgumentException('Unsupported reconciliation target.'),
        };
    }

    private function ensureDirectionMatches(BankTransaction $transaction, Model $target): void
    {
        $expected = match (true) {
            $target instanceof Expense => BankTransactionDirection::Debit,
            $target instanceof FundAdjustment && $target->amount < 0 => BankTransactionDirection::Debit,
            default => BankTransactionDirection::Credit,
        };

        if ($transaction->direction !== $expected) {
            throw new InvalidArgumentException('Money-in transactions can only link to credits and money-out transactions to debits.');
        }
    }

    private function ensurePeriodIsOpen(BankTransaction $transaction): void
    {
        $this->periodGuard->ensureDateIsWritable($transaction->family_id, $transaction->transacted_at);
    }

    private function refreshStatus(BankTransaction $transaction): void
    {
        $transaction->refresh();
        $linkedAmount = (int) $transaction->links()->sum('amount');
        $status = $linkedAmount >= $transaction->amount
            ? ReconciliationStatus::Matched
            : ($linkedAmount > 0 ? ReconciliationStatus::Suggested : ReconciliationStatus::Unmatched);
        $transaction->forceFill(['status' => $status, 'ignored_reason' => null, 'disputed_reason' => null])->save();
    }
}
