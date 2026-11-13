<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpenseBudgetQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->canRecordPayments();
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $user = $this->user();

        return [
            'month' => ['sometimes', 'required', 'date_format:Y-m'],
            'category' => ['nullable', 'integer', Rule::exists(ExpenseCategory::class, 'id')->where('family_id', $user instanceof User ? ($user->current_family_id ?? $user->family_id) : null)],
            'kind' => ['sometimes', Rule::in(['actual', 'committed'])],
            'amount' => ['sometimes', 'integer', 'min:0', 'max:999999999'],
        ];
    }
}
