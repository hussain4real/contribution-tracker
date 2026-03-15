<?php

namespace App\Policies;

use App\Models\Contribution;
use App\Models\User;

class ContributionPolicy
{
    /**
     * Determine whether the user can view any models.
     *
     * Super Admins and Financial Secretaries can view all contributions.
     * Members can only view their own contributions list.
     */
    public function viewAny(User $user): bool
    {
        // All authenticated users can view contributions (with appropriate filtering)
        return true;
    }

    /**
     * Determine whether the user can view the model.
     *
     * Super Admins and Financial Secretaries can view any contribution.
     * Members can only view their own contributions.
     */
    public function view(User $user, Contribution $contribution): bool
    {
        // Super Admin and Financial Secretary can view any contribution
        if ($user->canViewAllMembers()) {
            return true;
        }

        // Members can only view their own contributions
        return $user->id === $contribution->user_id;
    }

    /**
     * Determine whether the user can create models.
     *
     * Only Super Admin can manually create contributions.
     * Normally contributions are auto-generated.
     */
    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can update the model.
     *
     * Only Super Admin can modify contributions.
     */
    public function update(User $user, Contribution $contribution): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can delete the model.
     *
     * Only Super Admin can delete contributions.
     */
    public function delete(User $user, Contribution $contribution): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Contribution $contribution): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Contribution $contribution): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can view detailed member stats.
     *
     * FR-016: Members CANNOT see other members' individual contributions.
     */
    public function viewMemberDetails(User $user): bool
    {
        return $user->canViewAllMembers();
    }

    /**
     * Determine whether the user can view aggregate family stats.
     *
     * FR-015: Members CAN see family aggregate balance.
     */
    public function viewFamilyAggregate(User $user): bool
    {
        return true;
    }
}
