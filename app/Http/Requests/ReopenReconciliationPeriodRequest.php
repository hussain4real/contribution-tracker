<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Family;
use App\Models\ReconciliationPeriod;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class ReopenReconciliationPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $period = $this->route('reconciliation_period');
        $family = app(Family::class);

        return $user instanceof User
            && $period instanceof ReconciliationPeriod
            && $period->family_id === $family->id
            && $user->can('reopen-reconciliation')
            && $user->membershipForFamilyId($period->family_id) !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:1000']];
    }
}
