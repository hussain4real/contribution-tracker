<?php

declare(strict_types=1);

namespace App\Http\Requests;

class UpdateFamilyGoalRequest extends StoreFamilyGoalRequest
{
    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['idempotency_key']);

        return $rules;
    }
}
