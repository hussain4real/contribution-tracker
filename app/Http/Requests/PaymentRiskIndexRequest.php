<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\PaymentRiskAdvisoryBand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentRiskIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'period' => ['sometimes', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'band' => ['sometimes', 'nullable', Rule::enum(PaymentRiskAdvisoryBand::class)],
        ];
    }
}
