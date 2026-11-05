<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\Expense;
use App\Models\Family;
use App\Models\User;
use App\Services\FamilyFinancialVisibility;

class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        $family = $user->currentFamily ?? $user->family;

        return $family instanceof Family
            && app(FamilyFinancialVisibility::class)->transactions($user, $family);
    }

    public function view(User $user, Expense $expense): bool
    {
        return ($user->current_family_id ?? $user->family_id) === $expense->family_id
            && $user->membershipForFamilyId($expense->family_id) !== null
            && $this->viewAny($user);
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
