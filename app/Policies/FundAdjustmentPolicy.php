<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Family;
use App\Models\FundAdjustment;
use App\Models\User;
use App\Services\FamilyFinancialVisibility;

class FundAdjustmentPolicy
{
    public function viewAny(User $user): bool
    {
        $family = $user->currentFamily ?? $user->family;

        return $family instanceof Family
            && app(FamilyFinancialVisibility::class)->transactions($user, $family);
    }

    public function view(User $user, FundAdjustment $fundAdjustment): bool
    {
        return ($user->current_family_id ?? $user->family_id) === $fundAdjustment->family_id
            && $user->membershipForFamilyId($fundAdjustment->family_id) !== null
            && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->canRecordPayments();
    }

    public function update(User $user, FundAdjustment $fundAdjustment): bool
    {
        return false;
    }

    public function delete(User $user, FundAdjustment $fundAdjustment): bool
    {
        return ($user->current_family_id ?? $user->family_id) === $fundAdjustment->family_id
            && $user->membershipForFamilyId($fundAdjustment->family_id)?->role->canRecordPayments() === true;
    }

    public function restore(User $user, FundAdjustment $fundAdjustment): bool
    {
        return false;
    }

    public function forceDelete(User $user, FundAdjustment $fundAdjustment): bool
    {
        return false;
    }
}
