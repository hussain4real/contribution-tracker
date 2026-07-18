<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Payment $payment): bool
    {
        $contribution = $payment->contribution;

        if ($contribution === null) {
            return false;
        }

        $familyId = $contribution->family_id;

        if ($user->membershipForFamilyId($familyId) === null) {
            return false;
        }

        if ($user->canViewAllMembers()) {
            return true;
        }

        return $user->id === $contribution->user_id;
    }

    public function create(User $user): bool
    {
        return $user->canRecordPayments();
    }

    public function update(User $user, Payment $payment): bool
    {
        return false;
    }

    public function delete(User $user, Payment $payment): bool
    {
        return false;
    }

    public function restore(User $user, Payment $payment): bool
    {
        return false;
    }

    public function forceDelete(User $user, Payment $payment): bool
    {
        return false;
    }
}
