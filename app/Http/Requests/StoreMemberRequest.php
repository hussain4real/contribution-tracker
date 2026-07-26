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
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class StoreMemberRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->filled('family_category_id') || ! $this->filled('category')) {
            return;
        }

        $user = $this->user();
        $family = $user instanceof User ? ($user->currentFamily ?? $user->family) : null;

        if (! $family instanceof Family) {
            return;
        }

        $categoryId = $family->categories()
            ->where('slug', $this->string('category')->toString())
            ->value('id');

        if ($categoryId !== null) {
            $this->merge(['family_category_id' => $categoryId]);
        }
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->canAddMembers();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
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
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $user = $this->user();

            if (! $user instanceof User || $user->canManageRoles()) {
                return;
            }

            if ($this->input('role') !== Role::Member->value) {
                $validator->errors()->add('role', 'Only family admins can assign admin or financial secretary roles.');
            }
        });
    }

    /**
     * Get custom error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'The member name is required.',
            'email.required' => 'The email address is required.',
            'email.email' => 'Please provide a valid email address.',
            'email.unique' => 'This email address is already registered.',
            'password.required' => 'A password is required for new members.',
            'password.confirmed' => 'The password confirmation does not match.',
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
