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
        if ($user->family_id !== $contribution->family_id) {
            return false;
        }

        if ($user->canViewAllMembers()) {
            return true;
        }

        return $user->id === $contribution->user_id;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Contribution $contribution): bool
    {
        return $user->isAdmin() && $user->family_id === $contribution->family_id;
    }

    public function delete(User $user, Contribution $contribution): bool
    {
        return $user->isAdmin() && $user->family_id === $contribution->family_id;
    }

    public function restore(User $user, Contribution $contribution): bool
    {
        return $user->isAdmin() && $user->family_id === $contribution->family_id;
    }

    public function forceDelete(User $user, Contribution $contribution): bool
    {
        return $user->isAdmin() && $user->family_id === $contribution->family_id;
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
