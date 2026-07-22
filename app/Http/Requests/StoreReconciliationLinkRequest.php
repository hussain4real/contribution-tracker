<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\BankTransaction;
use App\Models\Expense;
use App\Models\Family;
use App\Models\FundAdjustment;
use App\Models\PaymentBatch;
use App\Models\ProviderSettlementGroup;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReconciliationLinkRequest extends FormRequest
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
            'target_type' => ['required', Rule::in([
                PaymentBatch::MORPH_TYPE,
                Expense::MORPH_TYPE,
                FundAdjustment::MORPH_TYPE,
                ProviderSettlementGroup::MORPH_TYPE,
            ])],
            'target_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
