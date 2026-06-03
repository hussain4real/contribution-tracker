<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePaymentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->canRecordPayments();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'member_id' => ['required', 'exists:users,id'],
            'amount' => ['required', 'integer', 'min:1'],
            'paid_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'target_year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'target_month' => ['nullable', 'integer', 'min:1', 'max:12'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $memberId = $this->integerInput('member_id');

            if ($memberId !== null) {
                $member = User::query()->find($memberId);
                if ($member && ! $member->category) {
                    $validator->errors()->add('member_id', 'This member does not have a contribution category assigned.');
                }
            }

            $targetYear = $this->integerInput('target_year');
            $targetMonth = $this->integerInput('target_month');

            if ($targetYear !== null && $targetMonth !== null) {
                $targetDate = now()->setYear($targetYear)->setMonth($targetMonth)->startOfMonth();
                $maxAdvanceDate = now()->addMonths(6)->startOfMonth();

                if ($targetDate->gt($maxAdvanceDate)) {
                    $validator->errors()->add('target_month', 'Advance payments are limited to 6 months ahead.');
                }
            }
        });
    }

    private function integerInput(string $key): ?int
    {
        $value = $this->input($key);

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Get custom error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'member_id.required' => 'Please select a family member.',
            'member_id.exists' => 'The selected member does not exist.',
            'amount.required' => 'Please enter the payment amount.',
            'amount.integer' => 'The amount must be a whole number in Naira.',
            'amount.min' => 'The amount must be at least ₦5.',
            'paid_at.required' => 'Please enter the payment date.',
            'paid_at.date' => 'Please enter a valid date.',
        ];
    }
}
