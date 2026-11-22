<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->isAdmin();
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $user = $this->user();
        $familyId = $user instanceof User ? ($user->current_family_id ?? $user->family_id) : null;

        return [
            'expense_category_id' => ['nullable', 'integer', Rule::exists(ExpenseCategory::class, 'id')->where('family_id', $familyId)],
            'description' => ['required', 'string', 'max:1000'],
            'amount' => ['required', 'integer', 'min:1'],
            'day_of_month' => ['required', 'integer', 'between:1,31'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
