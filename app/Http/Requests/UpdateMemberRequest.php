<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\MemberCategory;
use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;

class UpdateMemberRequest extends FormRequest
{
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $memberId = $this->route('member')?->id ?? $this->route('member');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($memberId)],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'category' => ['required', new Enum(MemberCategory::class)],
            'role' => ['required', new Enum(Role::class)],
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
            'name.required' => 'The member name is required.',
            'email.required' => 'The email address is required.',
            'email.email' => 'Please provide a valid email address.',
            'email.unique' => 'This email address is already registered.',
            'password.confirmed' => 'The password confirmation does not match.',
            'category.required' => 'Please select a member category.',
            'role.required' => 'Please select a role for the member.',
        ];
    }
}
