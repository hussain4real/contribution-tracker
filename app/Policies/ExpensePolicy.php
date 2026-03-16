<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;

class ExpensePolicy
{
    /**
     * Determine whether the user can view any models.
     *
     * All authenticated members can view expenses.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     *
     * All authenticated members can view individual expenses.
     */
    public function view(User $user, Expense $expense): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     *
     * Only Super Admin and Financial Secretary can record expenses.
     */
    public function create(User $user): bool
    {
        return $user->canRecordPayments();
    }

    /**
     * Determine whether the user can update the model.
     *
     * Only Super Admin can update expenses.
     */
    public function update(User $user, Expense $expense): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can delete the model.
     *
     * Only Super Admin can delete expenses.
     */
    public function delete(User $user, Expense $expense): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Expense $expense): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Expense $expense): bool
    {
        return $user->isSuperAdmin();
    }
}
