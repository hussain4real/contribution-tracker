<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class StoreExpenseDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->canRecordPayments();
    }

    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return [
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'max:10240'],
            'expense_id' => ['nullable', 'required_without:expense_request_id', 'prohibits:expense_request_id', 'integer', 'min:1'],
            'expense_request_id' => ['nullable', 'required_without:expense_id', 'prohibits:expense_id', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
