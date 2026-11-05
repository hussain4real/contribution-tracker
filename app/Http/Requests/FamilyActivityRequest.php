<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Family;
use App\Models\User;
use App\Services\FamilyFinancialVisibility;
use Illuminate\Foundation\Http\FormRequest;

class FamilyActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        $family = $user instanceof User ? ($user->currentFamily ?? $user->family) : null;

        return $user instanceof User && $family instanceof Family
            && app(FamilyFinancialVisibility::class)->transactions($user, $family);
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
