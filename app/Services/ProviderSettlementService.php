<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BankTransactionDirection;
use App\Models\BankTransaction;
use App\Models\PaystackTransaction;
use App\Models\ProviderSettlementGroup;
use App\Models\ProviderSettlementItem;
use App\Models\User;
use App\Support\AuditEventRecorder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ProviderSettlementService
{
    public function __construct(
        private readonly ReconciliationLinkService $linkService,
        private readonly AuditEventRecorder $audit,
    ) {}

    /** @param list<int> $transactionIds */
    public function create(
        int $familyId,
        array $transactionIds,
        string $reference,
        string $settledAt,
        User $actor,
        ?BankTransaction $bankTransaction = null,
        ?string $notes = null,
    ): ProviderSettlementGroup {
        if ($transactionIds === []) {
            throw new InvalidArgumentException('Select at least one Paystack transaction.');
        }

        return DB::transaction(function () use ($familyId, $transactionIds, $reference, $settledAt, $actor, $bankTransaction, $notes): ProviderSettlementGroup {
            $transactions = PaystackTransaction::query()
                ->where('family_id', $familyId)
                ->whereIn('id', $transactionIds)
                ->whereNotNull('payment_batch_id')
                ->lockForUpdate()
                ->get();

            if ($transactions->count() !== count(array_unique($transactionIds))) {
                throw new InvalidArgumentException('Every Paystack transaction must be allocated in this family.');
            }

            $alreadyGrouped = ProviderSettlementItem::query()
                ->whereIn('paystack_transaction_id', $transactions->pluck('id'))
                ->exists();

            if ($alreadyGrouped) {
                throw new InvalidArgumentException('A selected Paystack transaction already belongs to a settlement group.');
            }

            if ($bankTransaction instanceof BankTransaction) {
                if ($bankTransaction->family_id !== $familyId || $bankTransaction->direction !== BankTransactionDirection::Credit) {
                    throw new InvalidArgumentException('The settlement bank transaction must be a credit for this family.');
                }
            }

            $items = $transactions->map(function (PaystackTransaction $transaction): array {
                $gross = $this->fromKobo($transaction->expectedGrossAmountKobo());
                $fee = $this->fromKobo($transaction->actual_fee_kobo ?? $transaction->estimated_fee_kobo ?? 0);
                $net = $this->fromKobo($transaction->settled_amount_kobo ?? max(0, ($gross * 100) - ($fee * 100)));

                return [
                    'paystack_transaction_id' => $transaction->id,
                    'payment_batch_id' => (int) $transaction->payment_batch_id,
                    'gross_amount' => $gross,
                    'fee_amount' => $fee,
                    'net_amount' => $net,
                ];
            });
            $gross = 0;
            $fee = 0;
            $net = 0;

            foreach ($items as $item) {
                $gross += $item['gross_amount'];
                $fee += $item['fee_amount'];
                $net += $item['net_amount'];
            }
            $bankAmount = $bankTransaction?->amount;
            $group = ProviderSettlementGroup::query()->create([
                'family_id' => $familyId,
                'bank_transaction_id' => $bankTransaction?->id,
                'provider' => 'paystack',
                'reference' => trim($reference),
                'settled_at' => $settledAt,
                'gross_amount' => $gross,
                'fee_amount' => $fee,
                'net_amount' => $net,
                'bank_amount' => $bankAmount,
                'difference' => $bankAmount === null ? 0 : $bankAmount - $net,
                'created_by' => $actor->id,
                'notes' => $notes,
            ]);

            $group->items()->createMany($items->all());

            if ($bankTransaction instanceof BankTransaction && $bankTransaction->remainingAmount() > 0) {
                $this->linkService->link(
                    $bankTransaction,
                    ProviderSettlementGroup::MORPH_TYPE,
                    $group->id,
                    min($bankTransaction->remainingAmount(), $net),
                    $actor,
                    'Paystack aggregate settlement.',
                );
            }

            $this->audit->record($group, 'reconciliation.settlement.created', $familyId, $actor->id, after: [
                'reference' => $group->reference,
                'transactions' => $items->count(),
                'gross_amount' => $gross,
                'fee_amount' => $fee,
                'net_amount' => $net,
                'bank_amount' => $bankAmount,
                'difference' => $group->difference,
            ]);

            return $group->load('items');
        }, attempts: 3);
    }

    private function fromKobo(int $amount): int
    {
        if ($amount % 100 !== 0) {
            throw new InvalidArgumentException('Paystack settlement values must resolve to whole Naira amounts.');
        }

        return intdiv($amount, 100);
    }
}
