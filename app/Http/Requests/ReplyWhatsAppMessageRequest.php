<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReplyWhatsAppMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Inbox-level role authorization is enforced by the controller
     * (canViewAllMembers); this request only validates the payload.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:4096'],
        ];
    }
}
