<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFinancialGovernanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->isAdmin();
    }

    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return [
            'member_financial_visibility' => ['required', 'in:personal,summary,transactions'],
            'enabled' => ['required', 'boolean'],
            'threshold' => ['required', 'integer', 'min:1', 'max:999999999'],
            'approvers' => ['required', 'integer', 'min:1', 'max:10'],
        ];
    }
}
