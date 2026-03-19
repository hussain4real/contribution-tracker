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
        return $user->family_id === $expense->family_id;
    }

    public function create(User $user): bool
    {
        return $user->canRecordPayments();
    }

    public function update(User $user, Expense $expense): bool
    {
        return $user->isAdmin() && $user->family_id === $expense->family_id;
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $user->isAdmin() && $user->family_id === $expense->family_id;
    }

    public function restore(User $user, Expense $expense): bool
    {
        return $user->isAdmin() && $user->family_id === $expense->family_id;
    }

    public function forceDelete(User $user, Expense $expense): bool
    {
        return $user->isAdmin() && $user->family_id === $expense->family_id;
    }
}
