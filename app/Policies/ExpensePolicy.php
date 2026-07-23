<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
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
        return ($user->current_family_id ?? $user->family_id) === $expense->family_id
            && $user->membershipForFamilyId($expense->family_id) !== null;
    }

    public function create(User $user): bool
    {
        return $user->canRecordPayments();
    }

    public function update(User $user, Expense $expense): bool
    {
        return false;
    }

    public function delete(User $user, Expense $expense): bool
    {
        return ($user->current_family_id ?? $user->family_id) === $expense->family_id
            && $user->membershipForFamilyId($expense->family_id)?->role === Role::Admin;
    }

    public function restore(User $user, Expense $expense): bool
    {
        return false;
    }

    public function forceDelete(User $user, Expense $expense): bool
    {
        return false;
    }
}
