<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BankTransactionDirection;
use App\Enums\ReconciliationPeriodStatus;
use App\Enums\ReconciliationStatus;
use App\Models\BankTransaction;
use App\Models\Family;
use App\Models\ReconciliationPeriod;
use App\Models\User;
use App\Support\AuditEventRecorder;
use App\Support\EffectiveLedger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReconciliationPeriodService
{
    public function __construct(
        private readonly EffectiveLedger $ledger,
        private readonly AuditEventRecorder $audit,
    ) {}

    public function close(ReconciliationPeriod $period, User $actor): ReconciliationPeriod
    {
        return DB::transaction(function () use ($period, $actor): ReconciliationPeriod {
            $locked = ReconciliationPeriod::query()->lockForUpdate()->findOrFail($period->id);

            if (! in_array($locked->status, [
                ReconciliationPeriodStatus::Open,
                ReconciliationPeriodStatus::Reopened,
            ], true)) {
                throw new InvalidArgumentException('Only an open or reopened reconciliation period can be closed.');
            }

            $familyId = $locked->family_id;
            $startsAt = $locked->starts_at->toDateString();
            $endsAt = $locked->ends_at->toDateString();
            $paymentsBefore = (int) $this->ledger->paymentsForFamily($familyId)->whereDate('paid_at', '<', $startsAt)->sum('amount');
            $adjustmentsBefore = (int) $this->ledger->adjustmentsForFamily($familyId)->whereDate('recorded_at', '<', $startsAt)->sum('amount');
            $expensesBefore = (int) $this->ledger->expensesForFamily($familyId)->whereDate('spent_at', '<', $startsAt)->sum('amount');
            $opening = $paymentsBefore + $adjustmentsBefore - $expensesBefore;
            $payments = (int) $this->ledger->paymentsForFamily($familyId)->whereBetween('paid_at', [$startsAt, $endsAt])->sum('amount');
            $adjustments = (int) $this->ledger->adjustmentsForFamily($familyId)->whereBetween('recorded_at', [$startsAt, $endsAt])->sum('amount');
            $expenses = (int) $this->ledger->expensesForFamily($familyId)->whereBetween('spent_at', [$startsAt, $endsAt])->sum('amount');
            $ledgerNet = $payments + $adjustments - $expenses;
            $bankCredits = (int) BankTransaction::query()
                ->where('family_id', $familyId)
                ->where('direction', BankTransactionDirection::Credit)
                ->where('status', '!=', ReconciliationStatus::Ignored)
                ->whereBetween('transacted_at', [$startsAt, $endsAt])
                ->sum('amount');
            $bankDebits = (int) BankTransaction::query()
                ->where('family_id', $familyId)
                ->where('direction', BankTransactionDirection::Debit)
                ->where('status', '!=', ReconciliationStatus::Ignored)
                ->whereBetween('transacted_at', [$startsAt, $endsAt])
                ->sum('amount');
            $bankNet = $bankCredits - $bankDebits;

            $locked->forceFill([
                'status' => ReconciliationPeriodStatus::Closed,
                'opening_balance' => $opening,
                'closing_balance' => $opening + $ledgerNet,
                'bank_net' => $bankNet,
                'ledger_net' => $ledgerNet,
                'variance' => $bankNet - $ledgerNet,
                'closed_at' => now(),
                'closed_by' => $actor->id,
            ])->save();

            $this->audit->record($locked, 'reconciliation.period.closed', $familyId, $actor->id, after: $locked->only([
                'starts_at', 'ends_at', 'opening_balance', 'closing_balance', 'bank_net', 'ledger_net', 'variance',
            ]));

            return $locked;
        }, attempts: 3);
    }

    public function reopen(ReconciliationPeriod $period, User $actor, string $reason): ReconciliationPeriod
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A reopening reason is required.');
        }

        return DB::transaction(function () use ($period, $actor, $reason): ReconciliationPeriod {
            $locked = ReconciliationPeriod::query()->lockForUpdate()->findOrFail($period->id);

            if ($locked->status !== ReconciliationPeriodStatus::Closed) {
                throw new InvalidArgumentException('Only a closed reconciliation period can be reopened.');
            }

            $snapshot = $locked->only(['opening_balance', 'closing_balance', 'bank_net', 'ledger_net', 'variance', 'closed_at']);
            $locked->forceFill([
                'status' => ReconciliationPeriodStatus::Reopened,
                'reopened_at' => now(),
                'reopened_by' => $actor->id,
                'reopen_reason' => $reason,
            ])->save();

            $this->audit->record($locked, 'reconciliation.period.reopened', $locked->family_id, $actor->id, before: $snapshot, after: [
                ...$snapshot,
                'reopened_at' => $locked->reopened_at?->toIso8601String(),
                'reopen_reason' => $reason,
            ]);

            return $locked;
        }, attempts: 3);
    }

    public function create(Family $family, string $startsAt, string $endsAt): ReconciliationPeriod
    {
        $overlaps = ReconciliationPeriod::query()
            ->where('family_id', $family->id)
            ->whereDate('starts_at', '<=', $endsAt)
            ->whereDate('ends_at', '>=', $startsAt)
            ->exists();

        if ($overlaps) {
            throw new InvalidArgumentException('A reconciliation period already overlaps these dates.');
        }

        return ReconciliationPeriod::query()->create([
            'family_id' => $family->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => ReconciliationPeriodStatus::Open,
        ]);
    }
}
