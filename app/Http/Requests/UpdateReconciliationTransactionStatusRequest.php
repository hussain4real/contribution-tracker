<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ReconciliationStatus;
use App\Models\BankTransaction;
use App\Models\Family;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReconciliationTransactionStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $transaction = $this->route('bank_transaction');
        $family = app(Family::class);

        return $user instanceof User
            && $transaction instanceof BankTransaction
            && $transaction->family_id === $family->id
            && $user->can('reconcile-family-funds')
            && $user->membershipForFamilyId($transaction->family_id) !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([
                ReconciliationStatus::Unmatched->value,
                ReconciliationStatus::Ignored->value,
                ReconciliationStatus::Disputed->value,
            ])],
            'reason' => [
                Rule::requiredIf(in_array($this->input('status'), [ReconciliationStatus::Ignored->value, ReconciliationStatus::Disputed->value], true)),
                'nullable', 'string', 'min:5', 'max:1000',
            ],
        ];
    }
}
