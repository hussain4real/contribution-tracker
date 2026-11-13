<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class StoreExpenseCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->isAdmin();
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:80', 'not_regex:/^uncategorized$/i']];
    }
}
