<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;

class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Expense $expense): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->canRecordPayments();
    }

    public function update(User $user, Expense $expense): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $user->isSuperAdmin();
    }

    public function restore(User $user, Expense $expense): bool
    {
        return $user->isSuperAdmin();
    }

    public function forceDelete(User $user, Expense $expense): bool
    {
        return $user->isSuperAdmin();
    }
}
