<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class ArchiveFamilyGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->isAdmin();
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['archived' => ['required', 'boolean']];
    }
}
