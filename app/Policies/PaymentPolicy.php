<?php

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
        if ($user->canViewAllMembers()) {
            return true;
        }

        return $user->id === $payment->contribution?->user_id;
    }

    public function create(User $user): bool
    {
        return $user->canRecordPayments();
    }

    public function update(User $user, Payment $payment): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, Payment $payment): bool
    {
        return $user->isSuperAdmin()
            && $payment->created_at->diffInHours(now()) <= 24;
    }

    public function restore(User $user, Payment $payment): bool
    {
        return $user->isSuperAdmin();
    }

    public function forceDelete(User $user, Payment $payment): bool
    {
        return $user->isSuperAdmin();
    }
}
