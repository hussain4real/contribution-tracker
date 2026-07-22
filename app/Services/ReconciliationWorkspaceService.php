<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ReconciliationStatus;
use App\Enums\TransactionStatus;
use App\Models\BankTransaction;
use App\Models\Expense;
use App\Models\Family;
use App\Models\FundAdjustment;
use App\Models\PaymentBatch;
use App\Models\PaystackTransaction;
use App\Models\ProviderSettlementGroup;
use App\Models\ReconciliationImport;
use App\Models\ReconciliationLink;
use App\Models\ReconciliationPeriod;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ReconciliationWorkspaceService
{
    public function __construct(private readonly ReconciliationMatchingService $matchingService) {}

    /**
     * @param  array{status?: string|null, direction?: string|null, search?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function data(Family $family, User $actor, array $filters, ?ReconciliationImport $previewImport = null): array
    {
        $transactions = BankTransaction::query()
            ->where('family_id', $family->id)
            ->with(['links.reconcilable'])
            ->when(filled($filters['status'] ?? null), fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(filled($filters['direction'] ?? null), fn (Builder $query) => $query->where('direction', $filters['direction']))
            ->when(filled($filters['search'] ?? null), function (Builder $query) use ($filters): void {
                $search = '%'.strtolower((string) $filters['search']).'%';
                $query->where(function (Builder $query) use ($search): void {
                    $query->whereRaw('LOWER(COALESCE(reference, \'\')) LIKE ?', [$search])
                        ->orWhereRaw('LOWER(COALESCE(description, \'\')) LIKE ?', [$search]);
                });
            })
            ->latest('transacted_at')
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (BankTransaction $transaction): array => [
                'id' => $transaction->id,
                'date' => $transaction->transacted_at->toDateString(),
                'direction' => $transaction->direction->value,
                'direction_label' => $transaction->direction->label(),
                'amount' => $transaction->amount,
                'reference' => $transaction->reference,
                'description' => $transaction->description,
                'source_account' => $transaction->source_account,
                'status' => $transaction->status->value,
                'status_label' => $transaction->status->label(),
                'linked_amount' => $transaction->linkedAmount(),
                'remaining_amount' => $transaction->remainingAmount(),
                'ignored_reason' => $transaction->ignored_reason,
                'disputed_reason' => $transaction->disputed_reason,
                'suggestions' => $transaction->status === ReconciliationStatus::Matched
                    ? []
                    : $this->matchingService->suggestions($transaction)->all(),
                'links' => $transaction->links->map(fn (ReconciliationLink $link): array => [
                    'id' => $link->id,
                    'type' => $link->reconcilable_type,
                    'target_id' => $link->reconcilable_id,
                    'label' => $this->targetLabel($link->reconcilable),
                    'amount' => $link->amount,
                ])->all(),
            ]);

        $summary = collect(ReconciliationStatus::cases())->mapWithKeys(fn (ReconciliationStatus $status): array => [
            $status->value => BankTransaction::query()
                ->where('family_id', $family->id)
                ->where('status', $status)
                ->count(),
        ]);

        return [
            'filters' => $filters,
            'transactions' => $transactions,
            'summary' => $summary,
            'statuses' => collect(ReconciliationStatus::cases())->map(fn (ReconciliationStatus $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
            ]),
            'preview_import' => $this->preview($previewImport, $family),
            'imports' => $family->reconciliationImports()->latest()->limit(10)->get()->map(fn (ReconciliationImport $import): array => [
                'id' => $import->id,
                'name' => $import->original_name,
                'status' => $import->status->value,
                'rows' => $import->row_count,
                'imported' => $import->imported_count,
                'duplicates' => $import->duplicate_count,
                'created_at' => $import->created_at?->toIso8601String(),
            ]),
            'periods' => $family->reconciliationPeriods()->latest('starts_at')->limit(12)->get()->map(fn (ReconciliationPeriod $period): array => [
                'id' => $period->id,
                'starts_at' => $period->starts_at->toDateString(),
                'ends_at' => $period->ends_at->toDateString(),
                'status' => $period->status->value,
                'opening_balance' => $period->opening_balance,
                'closing_balance' => $period->closing_balance,
                'bank_net' => $period->bank_net,
                'ledger_net' => $period->ledger_net,
                'variance' => $period->variance,
                'reopen_reason' => $period->reopen_reason,
            ]),
            'settlements' => $family->providerSettlementGroups()->withCount('items')->latest('settled_at')->limit(12)->get()->map(fn (ProviderSettlementGroup $group): array => [
                'id' => $group->id,
                'reference' => $group->reference,
                'settled_at' => $group->settled_at->toDateString(),
                'transactions_count' => $this->numericAttribute($group, 'items_count'),
                'gross_amount' => $group->gross_amount,
                'fee_amount' => $group->fee_amount,
                'net_amount' => $group->net_amount,
                'bank_amount' => $group->bank_amount,
                'difference' => $group->difference,
            ]),
            'targets' => $this->targets($family),
            'paystack_transactions' => PaystackTransaction::query()
                ->where('family_id', $family->id)
                ->whereNotNull('payment_batch_id')
                ->whereIn('status', [TransactionStatus::Allocated, TransactionStatus::Success])
                ->whereDoesntHave('settlementItem')
                ->latest('id')
                ->limit(100)
                ->get()
                ->map(fn (PaystackTransaction $transaction): array => [
                    'id' => $transaction->id,
                    'reference' => $transaction->reference,
                    'amount' => $transaction->amount,
                    'settled_amount' => intdiv($transaction->settled_amount_kobo ?? $transaction->expectedGrossAmountKobo(), 100),
                ]),
            'can_reopen' => $actor->can('reopen-reconciliation'),
        ];
    }

    /** @return array<string, mixed>|null */
    private function preview(?ReconciliationImport $import, Family $family): ?array
    {
        if (! $import instanceof ReconciliationImport || $import->family_id !== $family->id) {
            return null;
        }

        return [
            'id' => $import->id,
            'name' => $import->original_name,
            'headers' => $import->headers,
            'rows' => $import->preview_rows,
            'status' => $import->status->value,
            'mapping' => $import->mapping,
        ];
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function targets(Family $family): array
    {
        $payments = array_values(PaymentBatch::query()->effective()->where('family_id', $family->id)
            ->withSum('reconciliationLinks as reconciled_amount', 'amount')->latest('paid_at')->limit(100)->get()
            ->map(fn (PaymentBatch $batch): array => [
                'type' => PaymentBatch::MORPH_TYPE, 'id' => $batch->id,
                'label' => "Receipt #{$batch->receipt_number} · {$batch->member_name}",
                'amount' => $batch->total_amount, 'remaining' => max(0, $batch->total_amount - $this->numericAttribute($batch, 'reconciled_amount')),
            ])->all());
        $expenses = array_values(Expense::query()->effective()->where('family_id', $family->id)
            ->withSum('reconciliationLinks as reconciled_amount', 'amount')->latest('spent_at')->limit(100)->get()
            ->map(fn (Expense $expense): array => [
                'type' => Expense::MORPH_TYPE, 'id' => $expense->id, 'label' => $expense->description,
                'amount' => $expense->amount, 'remaining' => max(0, $expense->amount - $this->numericAttribute($expense, 'reconciled_amount')),
            ])->all());
        $adjustments = array_values(FundAdjustment::query()->effective()->where('family_id', $family->id)
            ->withSum('reconciliationLinks as reconciled_amount', 'amount')->latest('recorded_at')->limit(100)->get()
            ->map(fn (FundAdjustment $adjustment): array => [
                'type' => FundAdjustment::MORPH_TYPE, 'id' => $adjustment->id, 'label' => $adjustment->description,
                'amount' => abs($adjustment->amount), 'direction' => $adjustment->amount < 0 ? 'debit' : 'credit',
                'remaining' => max(0, abs($adjustment->amount) - $this->numericAttribute($adjustment, 'reconciled_amount')),
            ])->all());
        $settlements = array_values(ProviderSettlementGroup::query()->where('family_id', $family->id)
            ->withSum('reconciliationLinks as reconciled_amount', 'amount')->latest('settled_at')->limit(100)->get()
            ->map(fn (ProviderSettlementGroup $group): array => [
                'type' => ProviderSettlementGroup::MORPH_TYPE, 'id' => $group->id,
                'label' => "Paystack settlement {$group->reference}", 'amount' => $group->net_amount,
                'remaining' => max(0, $group->net_amount - $this->numericAttribute($group, 'reconciled_amount')),
            ])->all());

        return ['payments' => $payments, 'expenses' => $expenses, 'adjustments' => $adjustments, 'settlements' => $settlements];
    }

    private function targetLabel(object $target): string
    {
        return match (true) {
            $target instanceof PaymentBatch => "Receipt #{$target->receipt_number} · {$target->member_name}",
            $target instanceof Expense => $target->description,
            $target instanceof FundAdjustment => $target->description,
            $target instanceof ProviderSettlementGroup => "Paystack settlement {$target->reference}",
            default => 'Ledger entry',
        };
    }

    private function numericAttribute(Model $model, string $attribute): int
    {
        $value = $model->getAttribute($attribute);

        return is_numeric($value) ? (int) $value : 0;
    }
}
