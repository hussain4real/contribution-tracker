<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FundAdjustment;
use App\Models\User;

class FundAdjustmentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, FundAdjustment $fundAdjustment): bool
    {
        return ($user->current_family_id ?? $user->family_id) === $fundAdjustment->family_id
            && $user->membershipForFamilyId($fundAdjustment->family_id) !== null;
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
