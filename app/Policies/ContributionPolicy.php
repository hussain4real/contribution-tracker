<?php

namespace App\Policies;

use App\Models\Contribution;
use App\Models\User;

class ContributionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Contribution $contribution): bool
    {
        if ($user->canViewAllMembers()) {
            return true;
        }

        return $user->id === $contribution->user_id;
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, Contribution $contribution): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, Contribution $contribution): bool
    {
        return $user->isSuperAdmin();
    }

    public function restore(User $user, Contribution $contribution): bool
    {
        return $user->isSuperAdmin();
    }

    public function forceDelete(User $user, Contribution $contribution): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * FR-016: Members CANNOT see other members' individual contributions.
     */
    public function viewMemberDetails(User $user): bool
    {
        return $user->canViewAllMembers();
    }

    /**
     * FR-015: Members CAN see family aggregate balance.
     */
    public function viewFamilyAggregate(User $user): bool
    {
        return true;
    }
}
