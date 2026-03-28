<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreFundAdjustmentRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1'],
            'description' => ['required', 'string', 'max:1000'],
            'recorded_at' => ['required', 'date'],
        ];
    }

    /**
     * Get custom error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.required' => 'Please enter the adjustment amount.',
            'amount.integer' => 'The amount must be a whole number in Naira.',
            'amount.min' => 'The amount must be at least ₦1.',
            'description.required' => 'Please enter a description for the adjustment.',
            'description.max' => 'The description must not exceed 1000 characters.',
            'recorded_at.required' => 'Please enter the date.',
            'recorded_at.date' => 'Please enter a valid date.',
        ];
    }
}
