<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ArchiveFamilyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'confirmation' => ['required', 'in:ARCHIVE'],
        ];
    }

    public function reason(): string
    {
        $reason = $this->validated('reason');

        return is_string($reason) ? $reason : '';
    }
}
