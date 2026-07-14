<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\Family;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateMemberRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $values = [];

        if (! $this->filled('display_name') && $this->filled('name')) {
            $values['display_name'] = $this->string('name')->toString();
        }

        if (! $this->filled('family_category_id') && $this->filled('category')) {
            $user = $this->user();
            $family = $user instanceof User ? ($user->currentFamily ?? $user->family) : null;

            if ($family instanceof Family) {
                $categoryId = $family->categories()
                    ->where('slug', $this->string('category')->toString())
                    ->value('id');

                if ($categoryId !== null) {
                    $values['family_category_id'] = $categoryId;
                }
            }
        }

        if ($values !== []) {
            $this->merge($values);
        }
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->canManageMembers() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'max:255'],
            'family_category_id' => [
                'required',
                'integer',
                Rule::exists('family_categories', 'id')->where(
                    function (QueryBuilder $query): void {
                        $query->where('family_id', $this->familyId());
                    },
                ),
            ],
            'role' => ['required', new Enum(Role::class)],
            'effective_immediately' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Get custom error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'display_name.required' => 'The member display name is required.',
            'family_category_id.required' => 'Please select a member category.',
            'role.required' => 'Please select a role for the member.',
        ];
    }

    private function familyId(): int
    {
        $user = $this->user();
        $family = $user instanceof User ? ($user->currentFamily ?? $user->family) : null;

        return $family instanceof Family ? $family->id : 0;
    }
}
