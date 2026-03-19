<?php

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
        return $user->family_id === $fundAdjustment->family_id;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, FundAdjustment $fundAdjustment): bool
    {
        return $user->isAdmin() && $user->family_id === $fundAdjustment->family_id;
    }

    public function delete(User $user, FundAdjustment $fundAdjustment): bool
    {
        return $user->isAdmin() && $user->family_id === $fundAdjustment->family_id;
    }

    public function restore(User $user, FundAdjustment $fundAdjustment): bool
    {
        return $user->isAdmin() && $user->family_id === $fundAdjustment->family_id;
    }

    public function forceDelete(User $user, FundAdjustment $fundAdjustment): bool
    {
        return $user->isAdmin() && $user->family_id === $fundAdjustment->family_id;
    }
}
