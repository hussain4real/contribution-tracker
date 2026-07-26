<?php

declare(strict_types=1);

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
        if ($user->membershipForFamilyId($contribution->family_id) === null) {
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
        return $user->isAdmin() && $user->membershipForFamilyId($contribution->family_id) !== null;
    }

    public function delete(User $user, Contribution $contribution): bool
    {
        return $user->isAdmin() && $user->membershipForFamilyId($contribution->family_id) !== null;
    }

    public function restore(User $user, Contribution $contribution): bool
    {
        return false;
    }

    public function forceDelete(User $user, Contribution $contribution): bool
    {
        return false;
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
