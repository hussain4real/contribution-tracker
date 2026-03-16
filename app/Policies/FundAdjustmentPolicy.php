<?php

namespace App\Policies;

use App\Models\FundAdjustment;
use App\Models\User;

class FundAdjustmentPolicy
{
    /**
     * Determine whether the user can view any models.
     *
     * All authenticated members can view fund adjustments.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     *
     * All authenticated members can view individual fund adjustments.
     */
    public function view(User $user, FundAdjustment $fundAdjustment): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     *
     * Only Super Admin can create fund adjustments.
     */
    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can update the model.
     *
     * Only Super Admin can update fund adjustments.
     */
    public function update(User $user, FundAdjustment $fundAdjustment): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can delete the model.
     *
     * Only Super Admin can delete fund adjustments.
     */
    public function delete(User $user, FundAdjustment $fundAdjustment): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, FundAdjustment $fundAdjustment): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, FundAdjustment $fundAdjustment): bool
    {
        return $user->isSuperAdmin();
    }
}
