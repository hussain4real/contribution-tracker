<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFamilyReminderSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->isAdmin();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reminder_day' => ['required', 'integer', 'min:1', 'max:28'],
            'follow_up_day' => ['required', 'integer', 'min:1', 'max:28', 'gt:reminder_day'],
            'time' => ['required', 'date_format:H:i'],
            'channels' => ['present', 'array'],
            'channels.*' => ['string', 'distinct', Rule::in(['mail', 'whatsapp', 'webpush'])],
        ];
    }
}
