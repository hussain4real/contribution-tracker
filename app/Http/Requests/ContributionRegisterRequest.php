<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\PaymentStatus;
use App\Models\Family;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContributionRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('generate-reports') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $user = $this->user();
        $family = $user instanceof User ? ($user->currentFamily ?? $user->family) : null;

        return [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'member_id' => [
                'nullable',
                'integer',
                Rule::exists('family_members', 'user_id')->where('family_id', $family instanceof Family ? $family->id : 0),
            ],
            'category' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(array_map(fn (PaymentStatus $status): string => $status->value, PaymentStatus::cases()))],
            'min_outstanding' => ['nullable', 'integer', 'min:0'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', Rule::in([25, 50, 100])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'date_from' => $validated['date_from'] ?? now()->startOfYear()->toDateString(),
            'date_to' => $validated['date_to'] ?? now()->endOfYear()->toDateString(),
            'member_id' => $this->nullableInteger($validated['member_id'] ?? null),
            'category' => $validated['category'] ?? null,
            'status' => $validated['status'] ?? null,
            'min_outstanding' => $this->nullableInteger($validated['min_outstanding'] ?? null),
            'search' => $validated['search'] ?? null,
            'per_page' => $this->nullableInteger($validated['per_page'] ?? null) ?? 25,
        ];
    }

    private function nullableInteger(mixed $value): ?int
    {
        return is_numeric($value) ? intval($value) : null;
    }
}
