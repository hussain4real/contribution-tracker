<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Family;
use App\Models\ReconciliationImport;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CommitReconciliationImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $import = $this->route('reconciliation_import');
        $family = app(Family::class);

        return $user instanceof User
            && $import instanceof ReconciliationImport
            && $import->family_id === $family->id
            && $user->can('reconcile-family-funds')
            && $user->membershipForFamilyId($import->family_id) !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $import = $this->route('reconciliation_import');
        $headers = $import instanceof ReconciliationImport ? $import->headers : [];
        $column = ['nullable', 'string', Rule::in($headers)];

        return [
            'mapping' => ['required', 'array'],
            'mapping.date' => ['required', 'string', Rule::in($headers)],
            'mapping.amount' => $column,
            'mapping.direction' => $column,
            'mapping.credit' => $column,
            'mapping.debit' => $column,
            'mapping.reference' => $column,
            'mapping.description' => $column,
            'mapping.source_account' => $column,
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $mapping = $this->input('mapping', []);

                if (! is_array($mapping)) {
                    return;
                }

                $hasAmountDirection = filled($mapping['amount'] ?? null) && filled($mapping['direction'] ?? null);
                $hasCreditDebit = filled($mapping['credit'] ?? null) || filled($mapping['debit'] ?? null);

                if (! $hasAmountDirection && ! $hasCreditDebit) {
                    $validator->errors()->add('mapping.amount', 'Map amount and direction columns, or map separate credit/debit columns.');
                }
            },
        ];
    }

    /**
     * @return array{date: string, amount: string|null, direction: string|null, credit: string|null, debit: string|null, reference: string|null, description: string|null, source_account: string|null}
     */
    public function mapping(): array
    {
        $mapping = $this->validated('mapping');
        abort_unless(is_array($mapping) && is_string($mapping['date'] ?? null), 422);

        return [
            'date' => $mapping['date'],
            'amount' => is_string($mapping['amount'] ?? null) ? $mapping['amount'] : null,
            'direction' => is_string($mapping['direction'] ?? null) ? $mapping['direction'] : null,
            'credit' => is_string($mapping['credit'] ?? null) ? $mapping['credit'] : null,
            'debit' => is_string($mapping['debit'] ?? null) ? $mapping['debit'] : null,
            'reference' => is_string($mapping['reference'] ?? null) ? $mapping['reference'] : null,
            'description' => is_string($mapping['description'] ?? null) ? $mapping['description'] : null,
            'source_account' => is_string($mapping['source_account'] ?? null) ? $mapping['source_account'] : null,
        ];
    }
}
