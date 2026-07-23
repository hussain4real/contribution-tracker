<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BankTransactionDirection;
use App\Enums\ReconciliationStatus;
use App\Models\BankTransaction;
use App\Models\Expense;
use App\Models\FundAdjustment;
use App\Models\PaymentBatch;
use App\Models\ProviderSettlementGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class ReconciliationMatchingService
{
    public function __construct(private readonly ReconciliationLinkService $linkService) {}

    public function autoMatch(BankTransaction $transaction, User $actor): ?Model
    {
        $exact = $this->exactCandidates($transaction);

        if (count($exact) === 1) {
            $target = $exact[0];

            $this->linkService->link(
                $transaction,
                $target->getMorphClass(),
                $target->id,
                $transaction->amount,
                $actor,
                'Exact unique reference and amount match.',
            );

            return $target;
        }

        if ($this->suggestions($transaction)->isNotEmpty()) {
            $transaction->forceFill(['status' => ReconciliationStatus::Suggested])->save();
        }

        return null;
    }

    /** @return list<PaymentBatch|ProviderSettlementGroup> */
    public function exactCandidates(BankTransaction $transaction): array
    {
        if (! filled($transaction->reference)) {
            return [];
        }

        $reference = strtolower((string) $transaction->reference);

        if ($transaction->direction === BankTransactionDirection::Credit) {
            $batches = PaymentBatch::query()
                ->effective()
                ->where('family_id', $transaction->family_id)
                ->where('total_amount', $transaction->amount)
                ->whereRaw('LOWER(reference) = ?', [$reference])
                ->whereDoesntHave('reconciliationLinks')
                ->whereDoesntHave('paystackTransaction.settlementItem')
                ->get();
            $settlements = ProviderSettlementGroup::query()
                ->where('family_id', $transaction->family_id)
                ->where('net_amount', $transaction->amount)
                ->whereRaw('LOWER(reference) = ?', [$reference])
                ->whereDoesntHave('reconciliationLinks')
                ->get();

            return [...$batches->all(), ...$settlements->all()];
        }

        return [];
    }

    /**
     * @return Collection<int, array{type: string, id: int, label: string, amount: int, date: string}>
     */
    public function suggestions(BankTransaction $transaction): Collection
    {
        $from = $transaction->transacted_at->copy()->subDays(3)->toDateString();
        $to = $transaction->transacted_at->copy()->addDays(3)->toDateString();

        if ($transaction->direction === BankTransactionDirection::Credit) {
            $batches = PaymentBatch::query()
                ->effective()
                ->where('family_id', $transaction->family_id)
                ->where('total_amount', $transaction->amount)
                ->whereBetween('paid_at', [$from, $to])
                ->whereDoesntHave('paystackTransaction.settlementItem')
                ->limit(5)
                ->get()
                ->map(fn (PaymentBatch $batch): array => [
                    'type' => PaymentBatch::MORPH_TYPE,
                    'id' => $batch->id,
                    'label' => "Receipt #{$batch->receipt_number} · {$batch->member_name}",
                    'amount' => $batch->total_amount,
                    'date' => $batch->paid_at->toDateString(),
                ]);
            $adjustments = FundAdjustment::query()
                ->effective()
                ->where('family_id', $transaction->family_id)
                ->where('amount', $transaction->amount)
                ->whereBetween('recorded_at', [$from, $to])
                ->limit(5)
                ->get()
                ->map(fn (FundAdjustment $adjustment): array => [
                    'type' => FundAdjustment::MORPH_TYPE,
                    'id' => $adjustment->id,
                    'label' => $adjustment->description,
                    'amount' => $adjustment->amount,
                    'date' => $adjustment->recorded_at->toDateString(),
                ]);

            return $batches->concat($adjustments)->take(5)->values();
        }

        $expenses = Expense::query()
            ->effective()
            ->where('family_id', $transaction->family_id)
            ->where('amount', $transaction->amount)
            ->whereBetween('spent_at', [$from, $to])
            ->limit(5)
            ->get()
            ->map(fn (Expense $expense): array => [
                'type' => Expense::MORPH_TYPE,
                'id' => $expense->id,
                'label' => $expense->description,
                'amount' => $expense->amount,
                'date' => $expense->spent_at->toDateString(),
            ]);
        $adjustments = FundAdjustment::query()
            ->effective()
            ->where('family_id', $transaction->family_id)
            ->where('amount', -$transaction->amount)
            ->whereBetween('recorded_at', [$from, $to])
            ->limit(5)
            ->get()
            ->map(fn (FundAdjustment $adjustment): array => [
                'type' => FundAdjustment::MORPH_TYPE,
                'id' => $adjustment->id,
                'label' => $adjustment->description,
                'amount' => abs($adjustment->amount),
                'date' => $adjustment->recorded_at->toDateString(),
            ]);

        return $expenses->concat($adjustments)->take(5)->values();
    }

    /**
     * @param  Collection<int, BankTransaction>  $transactions
     * @return array<int, list<array{type: string, id: int, label: string, amount: int, date: string}>>
     */
    public function suggestionsFor(Collection $transactions): array
    {
        if ($transactions->isEmpty()) {
            return [];
        }

        $credits = $transactions->where('direction', BankTransactionDirection::Credit);
        $debits = $transactions->where('direction', BankTransactionDirection::Debit);
        $familyId = (int) $transactions->firstOrFail()->family_id;
        $batches = PaymentBatch::query()->whereRaw('1 = 0')->get();
        $expenses = Expense::query()->whereRaw('1 = 0')->get();

        if ($credits->isNotEmpty()) {
            $batches = PaymentBatch::query()
                ->effective()
                ->where('family_id', $familyId)
                ->whereDoesntHave('paystackTransaction.settlementItem')
                ->where(function (Builder $query) use ($credits): void {
                    foreach ($credits as $transaction) {
                        $query->orWhere(fn (Builder $candidate) => $candidate
                            ->where('total_amount', $transaction->amount)
                            ->whereBetween('paid_at', [
                                $transaction->transacted_at->copy()->subDays(3)->toDateString(),
                                $transaction->transacted_at->copy()->addDays(3)->toDateString(),
                            ]));
                    }
                })
                ->get();
        }

        if ($debits->isNotEmpty()) {
            $expenses = Expense::query()
                ->effective()
                ->where('family_id', $familyId)
                ->where(function (Builder $query) use ($debits): void {
                    foreach ($debits as $transaction) {
                        $query->orWhere(fn (Builder $candidate) => $candidate
                            ->where('amount', $transaction->amount)
                            ->whereBetween('spent_at', [
                                $transaction->transacted_at->copy()->subDays(3)->toDateString(),
                                $transaction->transacted_at->copy()->addDays(3)->toDateString(),
                            ]));
                    }
                })
                ->get();
        }
        $adjustments = FundAdjustment::query()
            ->effective()
            ->where('family_id', $familyId)
            ->where(function (Builder $query) use ($transactions): void {
                foreach ($transactions as $transaction) {
                    $amount = $transaction->direction === BankTransactionDirection::Credit
                        ? $transaction->amount
                        : -$transaction->amount;
                    $query->orWhere(fn (Builder $candidate) => $candidate
                        ->where('amount', $amount)
                        ->whereBetween('recorded_at', [
                            $transaction->transacted_at->copy()->subDays(3)->toDateString(),
                            $transaction->transacted_at->copy()->addDays(3)->toDateString(),
                        ]));
                }
            })
            ->get();

        return $transactions->mapWithKeys(function (BankTransaction $transaction) use ($batches, $expenses, $adjustments): array {
            $from = $transaction->transacted_at->copy()->subDays(3);
            $to = $transaction->transacted_at->copy()->addDays(3);

            if ($transaction->direction === BankTransactionDirection::Credit) {
                $suggestions = $batches
                    ->filter(fn (PaymentBatch $batch): bool => $batch->total_amount === $transaction->amount && $batch->paid_at->betweenIncluded($from, $to))
                    ->map(fn (PaymentBatch $batch): array => [
                        'type' => PaymentBatch::MORPH_TYPE,
                        'id' => $batch->id,
                        'label' => "Receipt #{$batch->receipt_number} · {$batch->member_name}",
                        'amount' => $batch->total_amount,
                        'date' => $batch->paid_at->toDateString(),
                    ]);
                $signedAmount = $transaction->amount;
            } else {
                $suggestions = $expenses
                    ->filter(fn (Expense $expense): bool => $expense->amount === $transaction->amount && $expense->spent_at->betweenIncluded($from, $to))
                    ->map(fn (Expense $expense): array => [
                        'type' => Expense::MORPH_TYPE,
                        'id' => $expense->id,
                        'label' => $expense->description,
                        'amount' => $expense->amount,
                        'date' => $expense->spent_at->toDateString(),
                    ]);
                $signedAmount = -$transaction->amount;
            }

            $adjustmentSuggestions = $adjustments
                ->filter(fn (FundAdjustment $adjustment): bool => $adjustment->amount === $signedAmount && $adjustment->recorded_at->betweenIncluded($from, $to))
                ->map(fn (FundAdjustment $adjustment): array => [
                    'type' => FundAdjustment::MORPH_TYPE,
                    'id' => $adjustment->id,
                    'label' => $adjustment->description,
                    'amount' => abs($adjustment->amount),
                    'date' => $adjustment->recorded_at->toDateString(),
                ]);

            return [$transaction->id => array_values($suggestions->concat($adjustmentSuggestions)->take(5)->all())];
        })->all();
    }
}
