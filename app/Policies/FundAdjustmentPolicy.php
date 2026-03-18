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
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, FundAdjustment $fundAdjustment): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, FundAdjustment $fundAdjustment): bool
    {
        return $user->isSuperAdmin();
    }

    public function restore(User $user, FundAdjustment $fundAdjustment): bool
    {
        return $user->isSuperAdmin();
    }

    public function forceDelete(User $user, FundAdjustment $fundAdjustment): bool
    {
        return $user->isSuperAdmin();
    }
}
