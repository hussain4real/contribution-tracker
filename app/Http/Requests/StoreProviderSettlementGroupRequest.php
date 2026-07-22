<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Family;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProviderSettlementGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('reconcile-family-funds');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $user = $this->user();
        $family = $user instanceof User ? ($user->currentFamily ?? $user->family) : null;
        $familyId = $family instanceof Family ? $family->id : 0;

        return [
            'reference' => ['required', 'string', 'max:255'],
            'settled_at' => ['required', 'date_format:Y-m-d'],
            'paystack_transaction_ids' => ['required', 'array', 'min:1'],
            'paystack_transaction_ids.*' => [
                'required', 'integer', 'distinct',
                Rule::exists('paystack_transactions', 'id')->where('family_id', $familyId),
            ],
            'bank_transaction_id' => [
                'nullable', 'integer',
                Rule::exists('bank_transactions', 'id')->where('family_id', $familyId),
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return list<int> */
    public function transactionIds(): array
    {
        return array_values(array_map(
            fn (mixed $id): int => is_numeric($id) ? (int) $id : 0,
            $this->array('paystack_transaction_ids'),
        ));
    }
}
