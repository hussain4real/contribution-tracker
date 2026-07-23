<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class StoreReconciliationImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('reconcile-family-funds');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'statement' => ['required', File::types(['csv', 'txt'])->max('10mb'), 'extensions:csv,txt'],
        ];
    }
}
