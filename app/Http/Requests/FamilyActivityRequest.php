<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class FamilyActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->canViewAllMembers();
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'period' => ['sometimes', 'date_format:Y-m'],
            'type' => ['sometimes', 'in:all,payment,expense,adjustment'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
