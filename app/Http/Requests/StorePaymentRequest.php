<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePaymentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->canRecordPayments();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
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
            // Validate member exists and has a category
            if ($this->member_id) {
                $member = User::find($this->member_id);
                if ($member && ! $member->category) {
                    $validator->errors()->add('member_id', 'This member does not have a contribution category assigned.');
                }
            }

            // Validate target month is within 6 months ahead (FR-018)
            if ($this->target_year && $this->target_month) {
                $targetDate = now()->setYear($this->target_year)->setMonth($this->target_month)->startOfMonth();
                $currentMonth = now()->startOfMonth();
                $maxAdvanceDate = now()->addMonths(6)->startOfMonth();

                // Cannot target past months
                if ($targetDate->lt($currentMonth)) {
                    $validator->errors()->add('target_month', 'Cannot record payments for past months.');
                }

                // Cannot target more than 6 months ahead
                if ($targetDate->gt($maxAdvanceDate)) {
                    $validator->errors()->add('target_month', 'Advance payments are limited to 6 months ahead.');
                }
            }
        });
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
            'amount.integer' => 'The amount must be a whole number (in kobo).',
            'amount.min' => 'The amount must be at least ₦0.01.',
            'paid_at.required' => 'Please enter the payment date.',
            'paid_at.date' => 'Please enter a valid date.',
        ];
    }
}
